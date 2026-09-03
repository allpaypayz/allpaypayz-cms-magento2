<?php

declare(strict_types=1);

namespace Allpaypayz\Magento2\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BaseUrl implements OptionSourceInterface
{
    /** @return array<int, array{value: string, label: string}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'https://api4.allpaypayz.com', 'label' => 'Production'],
            ['value' => 'https://staging-api4.allpaypayz.com', 'label' => 'Staging'],
        ];
    }
}
