<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Models;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Gateway\Gateway;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceStripe\Database\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A Stripe payment intent stored for a transaction.
 *
 * @property int $id
 * @property int $gatewayId
 * @property int $customerId The ID of the {@see Customer} row
 * @property string $transactionHash
 * @property string|null $reference The Stripe payment intent ID
 * @property string|null $intentData
 * @property-read Customer|null $customer
 */
class PaymentIntent extends BaseModel
{
    use HasUid;

    #[Override]
    protected $table = Table::PAYMENTINTENTS;

    #[Override]
    protected $casts = [
        'gatewayId' => 'integer',
        'customerId' => 'integer',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customerId');
    }

    public function getGateway(): ?Gateway
    {
        return app(Gateways::class)->getGatewayById($this->gatewayId);
    }

    public function getTransaction(): ?Transaction
    {
        return app(Transactions::class)->getTransactionByHash($this->transactionHash);
    }

    public function __toString(): string
    {
        return (string)$this->reference;
    }
}
