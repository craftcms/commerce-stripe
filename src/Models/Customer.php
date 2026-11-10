<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Models;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Commerce\Payment\Gateway\Gateway;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\CommerceStripe\Database\Table;
use Override;

/**
 * A Stripe customer, linking a Craft user to a customer in the Stripe account of a gateway.
 *
 * @property int $id
 * @property int $userId
 * @property int $gatewayId
 * @property string $reference The Stripe customer ID
 * @property array<string, mixed>|null $response The Stripe customer object, when the customer was created
 */
class Customer extends BaseModel
{
    use HasUid;

    #[Override]
    protected $table = Table::CUSTOMERS;

    #[Override]
    protected $casts = [
        'userId' => 'integer',
        'gatewayId' => 'integer',
        'response' => 'array',
    ];

    public function getUser(): ?User
    {
        return Users::getUserById($this->userId);
    }

    public function getGateway(): ?Gateway
    {
        return app(Gateways::class)->getGatewayById($this->gatewayId);
    }

    public function __toString(): string
    {
        return (string)$this->reference;
    }
}
