<?php

namespace App\Http\Controllers;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductComponent;
use Illuminate\Http\Request;

/**
 * Extras of the article card, as the KVS creator showed: price lists,
 * combo components and alternate codes.
 */
class ProductExtrasController extends Controller
{
    public function prices(Product $product)
    {
        return $product->priceLists()->orderBy('nombre')->get();
    }

    public function storePrice(Request $r, Product $product)
    {
        $d = $r->validate([
            'nombre' => ['required', 'string'],
            'precio' => ['required', 'numeric', 'min:0'],
        ]);
        return response()->json($product->priceLists()->create($d), 201);
    }

    public function destroyPrice(PriceList $price)
    {
        $price->delete();
        return response()->noContent();
    }

    public function components(Product $product)
    {
        return $product->components()->with('component:id,codigo,descripcion,stock')->get();
    }

    public function storeComponent(Request $r, Product $product)
    {
        $d = $r->validate([
            'component_id' => ['required', 'exists:products,id'],
            'cantidad' => ['required', 'numeric', 'min:0.01'],
        ]);
        if ((int) $d['component_id'] === $product->id) {
            abort(422, 'Un combo no puede contenerse a si mismo.');
        }
        $c = $product->components()->create($d);
        return response()->json($c->load('component:id,codigo,descripcion,stock'), 201);
    }

    public function destroyComponent(ProductComponent $component)
    {
        $component->delete();
        return response()->noContent();
    }

    public function codes(Product $product)
    {
        return $product->codes()->orderBy('codigo')->get();
    }

    public function storeCode(Request $r, Product $product)
    {
        $d = $r->validate(['codigo' => ['required', 'string']]);
        return response()->json($product->codes()->firstOrCreate($d), 201);
    }

    public function destroyCode(ProductCode $code)
    {
        $code->delete();
        return response()->noContent();
    }
}
