<?php
/**
 * This file is part of the Dubas Stripe Integration - EspoCRM extension.
 *
 * dubas s.c. - contact@dubas.pro
 * Copyright (C) 2023-2024 Arkadiy Asuratov, Emil Dubielecki
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Espo\Modules\DubasStripeIntegration\Hooks\Invoice;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * @implements BeforeSave<Entity>
 */
class StripeCreatePayment implements BeforeSave
{
    public function __construct(
        private EntityManager $entityManager
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() && $entity->get('transactionLink') == 'created') {
            $amount = 75;
            if (str_contains($entity->get('name'), 'Engine')) {
                $amount = 150;
            } elseif (str_contains($entity->get('name'), 'Transmission')) {
                $amount = 100;
            }
            $transaction = $this->entityManager->createEntity('StripeTransaction', [
                'name' => 'Finder Fee',
                'status' => 'active',
                'transactionLink' => 'created',
                'amount' => $amount,
                'amountCurrency' => 'usd',
                'parentType' => 'Invoice',
                'parentId' => $entity->getId(),
            ]);
            $this->entityManager->saveEntity($transaction);
            $entity->set('stripeUrl', $transaction->get('paymentUrl'));

            // Send email only when a new invoice is created
            $lead = $this->entityManager->getEntityById('Lead', $entity->get('leadId'));
            $partVendor = $this->entityManager->getEntityById('PartVendor', $entity->get('partVendorId'));
            $name = str_replace("&", "and", $partVendor->get('name'));
            //$body = "Dear Customer, Here is your Yard Details. Name: $name, Email: " . $partVendor->get('emailAddress') . ", Number: " . $partVendor->get('phoneNumber') . ", Address: " . $partVendor->get('billingAddressStreet') . ", Stock: " . $entity->get('stock');
	    $body = "Dear Customer, Here is your Yard Details.\n\n" .
        	"Name: $name\n" .
        	"Email: " . $partVendor->get('emailAddress') . "\n" .
        	"Number: " . $partVendor->get('phoneNumber') . "\n" .
        	"Address: " . $partVendor->get('billingAddressStreet') . "\n" .
        	"Stock: " . $entity->get('stock');
            $this->sendSms($lead->get('phoneNumber'), $body);
        }

        // Additional logic for other conditions or actions

        $toProcess =
            (
                $entity->isNew() ||
                $entity->isAttributeChanged('status')
            ) &&
            (
                $entity->get('status') === 'Confirmed' &&
                $entity->get('stripePayment') === true
            );

        if (!$toProcess) {
            return;
        }

        $amount = 75; // You may need to define $amount here as per your logic
        $transaction = $this->entityManager->createEntity('StripeTransaction', [
            'name' => 'Finder Fee',
            'status' => 'active',
            'amount' => $amount,
            'amountCurrency' => $entity->get('grandTotalAmountCurrency'),
            'parentType' => 'Invoice',
            'parentId' => $entity->getId(),
        ]);

        $entity->set('stripeUrl', $transaction->get('paymentUrl'));
    }

    public function sendSms($phone, $body)
    {
        $curl = curl_init();
        $body = str_replace(" ", "%20", $body);
        $body = str_replace("#", " ", $body);
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://app2.simpletexting.com/v1/send?token=55e08c1d8c4a827998a3bb28c1053c36&phone=$phone&message=$body",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => array(
                "accept: application/json",
                "content-type: application/x-www-form-urlencoded"
            ),
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            $GLOBALS['log']->error("SMS Sending Error: " . $err);
        } else {
            $GLOBALS['log']->error("SMS Send API Response: " . $response);
        }
    }
}
