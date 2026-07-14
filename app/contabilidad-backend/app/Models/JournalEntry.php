<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JournalEntry extends Model {
    protected $fillable = ['company_id','numero','fecha','concepto','origen_type','origen_id','total_debe','total_haber','estado'];
    protected $casts = ['fecha'=>'date'];
    public function lines() { return $this->hasMany(JournalEntryLine::class); }
}
