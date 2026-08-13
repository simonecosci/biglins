<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCurrentCompanyRequest;
use Illuminate\Http\RedirectResponse;

class CurrentCompanyController extends Controller
{
    /**
     * Switch the current company for this session.
     */
    public function update(UpdateCurrentCompanyRequest $request): RedirectResponse
    {
        session(['current_company_id' => $request->validated('company_id')]);

        return back();
    }
}
