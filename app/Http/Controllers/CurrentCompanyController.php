<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCurrentCompanyRequest;
use Illuminate\Http\RedirectResponse;

class CurrentCompanyController extends Controller
{
    public function update(UpdateCurrentCompanyRequest $request): RedirectResponse
    {
        session(['current_company_id' => $request->validated('company_id')]);

        return back();
    }
}
