<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Service\Serializer;
use App\Exception\ApiException;
use Psr\Log\LoggerInterface;

final class CustomerApiController
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
        private readonly Serializer $serializer,
        private readonly LoggerInterface $logger,
    ) {}

    public function getCustomer(int $id): array
    {
        $customer = $this->customerRepository->find($id);

        if ($customer === null) {
            throw new ApiException('Customer not found', 404);
        }

        return $this->serializer->normalize($customer, ['detail', 'addresses', 'contacts']);
    }

    public function getCustomersBySegment(string $segment, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        $customers = $this->customerRepository->findBySegment($segment, $limit, $offset);
        $total = $this->customerRepository->countBySegment($segment);

        return [
            'data' => $this->serializer->normalize($customers, ['list']),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function createCustomer(array $data): array
    {
        $errors = $this->validateCustomerData($data);

        if (!empty($errors)) {
            throw new ApiException('Validation failed', 422, $errors);
        }

        $customer = new Customer(
            $data['email'],
            $data['company_name'],
            $data['contact_name'] ?? null
        );

        $customer->setPhone($data['phone'] ?? null);
        $customer->setSegment($data['segment'] ?? 'standard');

        $this->customerRepository->save($customer);

        $this->logger->info('Customer created via API', [
            'customer_id' => $customer->getId(),
            'email' => $data['email'],
        ]);

        return $this->serializer->normalize($customer, ['detail']);
    }

    public function updateCustomer(int $id, array $data): array
    {
        $customer = $this->customerRepository->find($id);

        if ($customer === null) {
            throw new ApiException('Customer not found', 404);
        }

        if (isset($data['company_name'])) {
            $customer->setCompanyName($data['company_name']);
        }

        if (isset($data['contact_name'])) {
            $customer->setContactName($data['contact_name']);
        }

        if (isset($data['phone'])) {
            $customer->setPhone($data['phone']);
        }

        $this->customerRepository->save($customer);

        $this->logger->info('Customer updated via API', [
            'customer_id' => $customer->getId(),
            'updates' => array_keys($data),
        ]);

        return $this->serializer->normalize($customer, ['detail']);
    }

    public function suspendCustomer(int $id, string $reason): array
    {
        $customer = $this->customerRepository->find($id);

        if ($customer === null) {
            throw new ApiException('Customer not found', 404);
        }

        if (!$customer->canBeSuspended()) {
            throw new ApiException('Customer cannot be suspended', 422);
        }

        $customer->suspend($reason);
        $this->customerRepository->save($customer);

        $this->logger->info('Customer suspended via API', [
            'customer_id' => $customer->getId(),
            'reason' => $reason,
        ]);

        return $this->serializer->normalize($customer, ['detail']);
    }

    private function validateCustomerData(array $data): array
    {
        $errors = [];

        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } elseif ($this->customerRepository->findByEmail($data['email']) !== null) {
            $errors['email'] = 'Email already exists';
        }

        if (empty($data['company_name'])) {
            $errors['company_name'] = 'Company name is required';
        } elseif (strlen($data['company_name']) < 2) {
            $errors['company_name'] = 'Company name must be at least 2 characters';
        }

        if (isset($data['phone']) && !preg_match('/^\+?[0-9]{10,15}$/', $data['phone'])) {
            $errors['phone'] = 'Invalid phone format';
        }

        return $errors;
    }
}
