<?php

declare(strict_types=1);

namespace App\Xml;

class UserXmlSerializer
{
    private string $rootElement = 'user';
    private string $namespace = 'http://api.example.com/users';

    public function serialize(User $user): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS($this->namespace, $this->rootElement);
        $doc->appendChild($root);

        $root->appendChild($this->createElement($doc, 'id', $user->getId()));
        $root->appendChild($this->createElement($doc, 'email', $user->getEmail()));
        $root->appendChild($this->createElement($doc, 'name', $user->getName()));

        if ($user->getAvatarUrl() !== null) {
            $root->appendChild($this->createElement($doc, 'avatarUrl', $user->getAvatarUrl()));
        }

        $root->appendChild($this->createElement($doc, 'createdAt', $user->getCreatedAt()->format('c')));

        if ($user->getUpdatedAt() !== null) {
            $root->appendChild($this->createElement($doc, 'updatedAt', $user->getUpdatedAt()->format('c')));
        }

        $root->appendChild($this->createElement($doc, 'isActive', $user->isActive() ? 'true' : 'false'));

        $rolesElement = $doc->createElement('roles');
        foreach ($user->getRoles() as $role) {
            $rolesElement->appendChild($this->createElement($doc, 'role', $role));
        }
        $root->appendChild($rolesElement);

        return $doc;
    }

    public function serializeToString(User $user): string
    {
        return $this->serialize($user)->saveXML();
    }

    public function deserialize(\DOMDocument $doc): User
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ns', $this->namespace);

        $root = $xpath->query('//ns:user')->item(0);

        if ($root === null) {
            throw new \InvalidArgumentException('Invalid user XML document');
        }

        $id = $this->getElementValue($xpath, $root, 'id');
        $email = $this->getElementValue($xpath, $root, 'email');
        $name = $this->getElementValue($xpath, $root, 'name');
        $avatarUrl = $this->getElementValue($xpath, $root, 'avatarUrl');
        $createdAt = new DateTimeImmutable($this->getElementValue($xpath, $root, 'createdAt'));
        $updatedAtStr = $this->getElementValue($xpath, $root, 'updatedAt');
        $updatedAt = $updatedAtStr ? new DateTimeImmutable($updatedAtStr) : null;
        $isActive = $this->getElementValue($xpath, $root, 'isActive') === 'true';

        $roles = [];
        $roleNodes = $xpath->query('ns:roles/ns:role', $root);
        foreach ($roleNodes as $roleNode) {
            $roles[] = $roleNode->textContent;
        }

        return new User(
            $id,
            $email,
            $name,
            $avatarUrl,
            $createdAt,
            $updatedAt,
            $isActive,
            $roles
        );
    }

    private function createElement(\DOMDocument $doc, string $name, string $value): \DOMElement
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode($value));
        return $element;
    }

    private function getElementValue(\DOMXPath $xpath, \DOMNode $context, string $name): ?string
    {
        $nodes = $xpath->query("ns:{$name}", $context);
        return $nodes->length > 0 ? $nodes->item(0)?->textContent : null;
    }
}
