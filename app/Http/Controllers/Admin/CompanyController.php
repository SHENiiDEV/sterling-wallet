<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');

        $companies = Company::query()
            ->with('parent:id,name')
            ->withCount(['merchants', 'children'])
            ->when($search !== '', fn (Builder $q) => $q->where(
                fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('registration_number', 'like', "%{$search}%"),
            ))
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/companies/index', [
            'companies' => CompanyResource::collection($companies)->resolve(),
            'parents' => Company::query()->orderBy('name')->get(['id', 'name', 'parent_id']),
            'filters' => ['search' => $search],
        ]);
    }

    public function store(CompanyRequest $request): RedirectResponse
    {
        Company::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Company created.']);

        return back();
    }

    public function update(CompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Company saved.']);

        return back();
    }

    public function destroy(Company $company): RedirectResponse
    {
        if ($company->merchants()->exists()) {
            throw ValidationException::withMessages([
                'company' => 'This company still has merchants. Move or remove them first.',
            ]);
        }

        $company->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Company deleted.']);

        return back();
    }
}
