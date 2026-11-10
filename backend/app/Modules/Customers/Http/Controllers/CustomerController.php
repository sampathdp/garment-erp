<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Actions\DeleteCustomer;
use App\Modules\Customers\Actions\SaveCustomer;
use App\Modules\Customers\Http\Requests\StoreCustomerRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use App\Support\Validation\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class CustomerController
{
    private const SORTS = ['name' => 'name', 'customer_code' => 'customer_code', 'created_at' => 'created_at'];

    private const COLUMNS = ['id', 'customer_code', 'page_id', 'branch_id', 'name', 'phone_e164', 'whatsapp_e164', 'alt_phone_e164', 'address_line1', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $request->validate([
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'filter.page_id' => ['sometimes', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $query = Customer::query()->visibleTo($user)->select(self::COLUMNS)
            ->with(['page:id,code,name', 'branch:id,code,name'])
            ->withCount('orders');

        if ($request->filled('filter.page_id')) {
            $query->where('page_id', (int) $request->input('filter.page_id'));
        }

        if ($search = trim((string) $request->input('filter.search'))) {
            $phone = PhoneNumber::normalize($search);
            $like = addcslashes($search, '%_\\');
            $query->where(function (Builder $q) use ($phone, $like): void {
                $q->where('name', 'like', "%{$like}%")->orWhere('customer_code', 'like', "{$like}%");
                if ($phone !== null) {
                    $q->orWhere('phone_e164', $phone)->orWhere('whatsapp_e164', $phone)->orWhere('alt_phone_e164', $phone);
                }
            });
        }

        ListQuery::applySort($query, $request, self::SORTS, 'name');

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), CustomerResource::class);
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return new CustomerResource($customer->load(['page:id,code,name', 'branch:id,code,name'])->loadCount('orders'));
    }

    public function store(StoreCustomerRequest $request, SaveCustomer $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $customer = $action->create($actor, (int) $request->validated('page_id'), $request->validated('branch_id'), $request->attributesToSave(), $request->boolean('confirm_distinct'));

        return (new CustomerResource($customer->load(['page:id,code,name', 'branch:id,code,name'])->loadCount('orders')))->response()->setStatusCode(201);
    }

    public function update(StoreCustomerRequest $request, Customer $customer, SaveCustomer $action): CustomerResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $customer = $action->update($actor, $customer, $request->attributesToSave(), $request->boolean('confirm_distinct'));

        return new CustomerResource($customer->load(['page:id,code,name', 'branch:id,code,name'])->loadCount('orders'));
    }

    public function destroy(Request $request, Customer $customer, DeleteCustomer $action): Response
    {
        Gate::authorize('delete', $customer);
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $customer);

        return response()->noContent();
    }
}
