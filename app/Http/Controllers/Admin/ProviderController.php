<?php

namespace App\Http\Controllers\Admin;

use App\Bots\ConnectorRegistry;
use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProviderRequest;
use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use App\Reports\Parsers\ParserRegistry;
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
            ...$this->formOptions(),
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
            ...$this->formOptions(),
        ]);
    }

    public function update(ProviderRequest $request, Provider $provider): RedirectResponse
    {
        $provider->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Provider saved.']);

        return to_route('admin.providers.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => ProviderType::options(),
            'reportFormats' => array_map(fn (string $f) => ['value' => $f, 'label' => ucfirst($f)], app(ParserRegistry::class)->formats()),
            'connectors' => app(ConnectorRegistry::class)->options(),
            'defaultMatching' => config('sterling.matching'),
            'defaultTimezone' => config('sterling.timezone'),
        ];
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
