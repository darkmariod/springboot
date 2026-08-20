<?php
namespace App\Models;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
class JournalEntry extends Model {
    use Auditable;
    protected $fillable = ['company_id','numero','fecha','concepto','origen_type','origen_id','total_debe','total_haber','estado'];
    protected $casts = ['fecha'=>'date'];
    public function lines() { return $this->hasMany(JournalEntryLine::class); }
}
