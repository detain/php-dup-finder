<?php
declare(strict_types=1);

namespace App\Core\Crm\Contact\Mapper;

use App\Domain\Entity\Contact;
use App\Core\DTO\DTOInterface;

interface ContactMappingStrategy
{
    public function getExtraFields(): array;
    public function includePersonalDetails(): bool;
}

abstract class BaseContactMapper
{
    public function map(Contact $contact, DTOInterface $dto, ?ContactMappingStrategy $strategy = null): DTOInterface
    {
        $dto->id = $contact->getId()->toString();
        $dto->firstName = $contact->getFirstName();
        $dto->lastName = $contact->getLastName();
        $dto->displayName = $contact->getDisplayName();
        $dto->email = $contact->getEmail();
        $dto->phone = $contact->getPhone();
        $dto->mobile = $contact->getMobile();
        $dto->companyName = $contact->getCompany()?->getName();
        $dto->jobTitle = $contact->getJobTitle();
        $dto->status = $contact->getStatus()->value;
        $dto->rating = $contact->getRating();
        $dto->tags = $contact->getTags();
        $dto->createdAt = $contact->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $contact->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        if ($strategy === null || $strategy->includePersonalDetails()) {
            $dto->middleName = $contact->getMiddleName();
            $dto->prefix = $contact->getPrefix();
            $dto->suffix = $contact->getSuffix();
            $dto->email2 = $contact->getEmail2();
            $dto->phone2 = $contact->getPhone2();
            $dto->fax = $contact->getFax();
            $dto->department = $contact->getDepartment();
            $dto->dateOfBirth = $contact->getDateOfBirth()?->format('Y-m-d');
            $dto->anniversary = $contact->getAnniversary()?->format('Y-m-d');
        }

        $dto->address = $this->mapAddress($contact->getAddress());
        $dto->notes = $contact->getNotes();
        $dto->lastContactedAt = $contact->getLastContactedAt()?->format(\DateTimeInterface::ATOM);
        $dto->source = $contact->getSource();

        if ($strategy !== null) {
            foreach ($strategy->getExtraFields() as $field => $value) {
                $dto->{$field} = $value;
            }
        }

        return $dto;
    }

    protected function mapAddress(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'state' => $address->getState(),
            'postalCode' => $address->getPostalCode(),
            'country' => $address->getCountry(),
            'isPrimary' => $address->isPrimary(),
        ];
    }
}

final class ContactApplicationMapper extends BaseContactMapper {}
final class ContactApiMapper extends BaseContactMapper {}
final class MarketingContactMapper extends BaseContactMapper {}
