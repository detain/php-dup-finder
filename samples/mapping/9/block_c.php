<?php
declare(strict_types=1);

namespace App\Crm\Contact\Marketing\Mapper;

use App\Domain\Entity\Contact;
use App\Crm\Contact\Marketing\DTO\MarketingContactDTO;

final readonly class MarketingContactMapper
{
    public function toMarketingDTO(Contact $contact): MarketingContactDTO
    {
        $dto = new MarketingContactDTO();
        $dto->id = $contact->getId()->toString();
        $dto->firstName = $contact->getFirstName();
        $dto->lastName = $contact->getLastName();
        $dto->middleName = $contact->getMiddleName();
        $dto->prefix = $contact->getPrefix();
        $dto->suffix = $contact->getSuffix();
        $dto->displayName = $contact->getDisplayName();
        $dto->email = $contact->getEmail();
        $dto->email2 = $contact->getEmail2();
        $dto->phone = $contact->getPhone();
        $dto->phone2 = $contact->getPhone2();
        $dto->mobile = $contact->getMobile();
        $dto->fax = $contact->getFax();
        $dto->companyName = $contact->getCompany()?->getName();
        $dto->jobTitle = $contact->getJobTitle();
        $dto->department = $contact->getDepartment();
        $dto->dateOfBirth = $contact->getDateOfBirth()?->format('Y-m-d');
        $dto->anniversary = $contact->getAnniversary()?->format('Y-m-d');
        $dto->status = $contact->getStatus()->value;
        $dto->source = $contact->getSource();
        $dto->rating = $contact->getRating();
        $dto->tags = $contact->getTags();
        $dto->address = $this->mapAddress($contact->getAddress());
        $dto->notes = $contact->getNotes();
        $dto->createdAt = $contact->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $contact->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $dto->lastContactedAt = $contact->getLastContactedAt()?->format(\DateTimeInterface::ATOM);
        $dto->marketingConsent = $contact->hasMarketingConsent();
        $dto->preferences = $contact->getPreferences();

        return $dto;
    }

    private function mapAddress(?Address $address): ?array
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
