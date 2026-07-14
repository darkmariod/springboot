<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        return Account::query()
            ->when($request->company_id, fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('codigo')
            ->get();
    }
}
