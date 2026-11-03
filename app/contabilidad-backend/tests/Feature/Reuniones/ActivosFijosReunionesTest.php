<?php

namespace Tests\Feature\Reuniones;

use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\ActivosFijosTestCase;

/**
 * Activos fijos en la reunión del 10 de julio: depreciación mensual automática y baja del activo.
 * Usa `ActivosFijosTestCase` (el «hoy» queda fijo en el 14 de octubre de 2026; el activo de ejemplo es una laptop de $1200 a 12 meses).
 */
#[Group('reuniones')]
class ActivosFijosReunionesTest extends ActivosFijosTestCase
{
    /**
     * Pedido por: CONS-10jul
     * Pedido: «Los activos fijos tienen que depreciarse solos cada mes, en línea recta, con un solo asiento para todos.»
     * Se prueba: laptop de $1200 a 12 meses deprecia $100 por mes; un rango de 3 meses deja 3 asientos y repetir un mes no duplica nada.
     */
    #[Group('reuniones')]
    #[Group('R19')]
    public function test_R19_los_activos_se_deprecian_cada_mes_en_linea_recta_con_un_asiento_por_mes(): void
    {
        // Arrange: dos activos, la laptop de $1200 y un escritorio de $600 a 12 meses
        $laptop = $this->activo();
        $this->activo(['nombre' => 'Escritorio', 'costo' => 600, 'grupo' => 'muebles_enseres']);

        // Act 1: se deprecia enero
        $r = $this->mes('2026-01')->assertCreated();

        // Assert 1: $100 + $50 en UN asiento del 31 de enero
        $this->assertEquals(150, $r->json('total'));
        $this->assertSame(2, $r->json('depreciados'));
        $enero = JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Depreciación%')->sole();
        $this->assertLineas($enero, [['5.4.01', 150, 0], ['1.2.02', 0, 150]]);
        $this->assertSame('2026-01-31', $enero->fecha->toDateString());

        // Act 2 + Assert 2: febrero y marzo en un rango, cada mes con su propio asiento
        $this->rango('2026-02', '2026-03')->assertCreated()->assertJsonPath('depreciados', 4);
        $this->assertSame(3, JournalEntry::where('company_id', $this->company->id)->where('concepto', 'like', 'Depreciación%')->count());
        $this->assertEquals(450, $this->saldo('depreciacion_acumulada', -1));
        $this->assertEquals(900, collect($this->getJson("/api/fixed-assets?company_id={$this->company->id}")->json('activos'))->firstWhere('id', $laptop->id)['valor_en_libros']);

        // Assert 3: depreciar otra vez un mes ya depreciado no duplica nada; un mes futuro se rechaza
        $this->mes('2026-03')->assertOk()->assertJsonPath('depreciados', 0);
        $this->assertSame(6, FixedAssetDepreciation::count());
        $this->mes('2027-05')->assertStatus(422);
        $this->chequeoEstricto()->assertExitCode(0);
    }

    /**
     * Pedido por: CONS-10jul
     * Pedido: «Y poder dar de baja un activo: que deprecie lo que falta y mande el valor en libros a pérdida.»
     * Se prueba: la baja de la laptop (3 meses depreciados) deprecia abril y asienta Debe 1.2.02 $400 y pérdida $800 contra el activo $1200; ya no se deprecia.
     */
    #[Group('reuniones')]
    #[Group('R19')]
    public function test_R19_la_baja_de_un_activo_deprecia_lo_pendiente_y_manda_el_valor_en_libros_a_perdida(): void
    {
        // Arrange: laptop depreciada de enero a marzo
        $laptop = $this->activo();
        $this->rango('2026-01', '2026-03')->assertCreated();

        // Act: baja el 10 de abril por obsoleta
        $r = $this->baja($laptop, ['motivo' => 'Obsoleto'])->assertOk();

        // Assert: abril se depreció con la fecha de la baja y el asiento de baja cuadra
        $baja = JournalEntry::findOrFail($r->json('asiento.id'));
        $this->assertLineas($baja, [['1.2.02', 400, 0], ['5.4.02', 800, 0], ['1.2.01', 0, 1200]]);
        $this->assertEquals(-800, $r->json('resultado'));
        $this->assertSame('dado_de_baja', $laptop->fresh()->estado);
        $this->assertSame(4, FixedAssetDepreciation::where('fixed_asset_id', $laptop->id)->count());
        $this->assertEquals(0, $this->saldo('activos_fijos'));
        $this->assertEquals(0, $this->saldo('depreciacion_acumulada', -1));
        // Assert: no se da de baja dos veces y ya no se deprecia
        $this->baja($laptop)->assertStatus(422);
        $this->mes('2026-05')->assertOk()->assertJsonPath('depreciados', 0);
        $this->chequeoEstricto()->assertExitCode(0);
    }
}
