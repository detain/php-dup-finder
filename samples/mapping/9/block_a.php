<?php
declare(strict_types=1);

namespace App\Crm\Contact\Application\Mapper;

use App\Domain\Entity\Contact;
use App\Crm\Contact\Application\DTO\ContactEntityDTO;
use App\Crm\Contact\Application\DTO\ContactViewDTO;
use App\Crm\Contact\Application\DTO\ContactListDTO;

final readonly class ContactApplicationMapper
{
    public function toEntityDTO(Contact $contact): ContactEntityDTO
    {
        $dto = new ContactEntityDTO();
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

        return $dto;
    }

    public function toViewDTO(Contact $contact): ContactViewDTO
    {
        $dto = new ContactViewDTO();
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
        $dto->activities = $this->formatActivities($contact->getActivities());

        return $dto;
    }

    public function toListDTO(Contact $contact): ContactListDTO
    {
        $dto = new ContactListDTO();
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

    private function formatActivities(array $activities): array
    {
        return array_map(fn($a) => [
            'type' => $a->getType(),
            'date' => $a->getDate()->format(\DateTimeInterface::ATOM),
            'subject' => $a->getSubject(),
        ], array_slice($activities, 0, 5));
    }
}
