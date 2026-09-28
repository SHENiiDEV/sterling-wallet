<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProviderRequest;
use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    public function index(): Response
    {
        $providers = Provider::query()
            ->withCount(['bankMids', 'gateMids', 'cryptoMerchants'])
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/providers/index', [
            'providers' => ProviderResource::collection($providers)->resolve(),
            'types' => ProviderType::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/providers/form', [
            'provider' => null,
            'types' => ProviderType::options(),
        ]);
    }

    public function store(ProviderRequest $request): RedirectResponse
    {
        Provider::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provider created.']);

        return to_route('admin.providers.index');
    }

    public function edit(Provider $provider): Response
    {
        return Inertia::render('admin/providers/form', [
            'provider' => ProviderResource::make($provider)->resolve(),
            'types' => ProviderType::options(),
        ]);
    }

    public function update(ProviderRequest $request, Provider $provider): RedirectResponse
    {
        $provider->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provider saved.']);

        return to_route('admin.providers.index');
    }

    public function destroy(Provider $provider): RedirectResponse
    {
        if ($provider->bankMids()->exists() || $provider->gateMids()->exists() || $provider->cryptoMerchants()->exists()) {
            throw ValidationException::withMessages([
                'provider' => 'This provider is assigned to merchants. Deactivate it instead.',
            ]);
        }

        $provider->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provider deleted.']);

        return to_route('admin.providers.index');
    }
}
