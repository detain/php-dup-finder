<?php

declare(strict_types=1);

namespace App\Xml;

abstract class XmlSerializer
{
    protected string $rootElement;
    protected string $namespace;

    abstract protected function getType(): string;

    public function serialize($entity): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS($this->namespace, $this->rootElement);
        $doc->appendChild($root);

        $this->serializeFields($entity, $doc, $root);

        return $doc;
    }

    abstract protected function serializeFields($entity, \DOMDocument $doc, \DOMElement $root): void;

    public function serializeToString($entity): string
    {
        return $this->serialize($entity)->saveXML();
    }

    public function deserialize(\DOMDocument $doc)
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ns', $this->namespace);

        $root = $xpath->query('//ns:' . $this->rootElement)->item(0);

        if ($root === null) {
            throw new \InvalidArgumentException("Invalid {$this->getType()} XML document");
        }

        return $this->deserializeFields($xpath, $root);
    }

    abstract protected function deserializeFields(\DOMXPath $xpath, \DOMNode $root);

    protected function createElement(\DOMDocument $doc, string $name, string $value): \DOMElement
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode($value));
        return $element;
    }

    protected function getElementValue(\DOMXPath $xpath, \DOMNode $context, string $name): ?string
    {
        $nodes = $xpath->query("ns:{$name}", $context);
        return $nodes->length > 0 ? $nodes->item(0)?->textContent : null;
    }

    protected function createNestedElement(\DOMDocument $doc, \DOMElement $parent, string $name, array $items, callable $transform): void
    {
        $element = $doc->createElement($name);
        foreach ($items as $item) {
            $itemElement = $doc->createElement('item');
            $transform($item, $doc, $itemElement);
            $element->appendChild($itemElement);
        }
        $parent->appendChild($element);
    }
}

class UserXmlSerializer extends XmlSerializer
{
    protected string $rootElement = 'user';
    protected string $namespace = 'http://api.example.com/users';

    protected function getType(): string
    {
        return 'user';
    }

    protected function serializeFields($user, \DOMDocument $doc, \DOMElement $root): void
    {
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

        $this->createNestedElement($doc, $root, 'roles', $user->getRoles(), function ($role, $doc, $itemElement) {
            $itemElement->appendChild($this->createElement($doc, 'role', $role));
        });
    }

    protected function deserializeFields(\DOMXPath $xpath, \DOMNode $root): User
    {
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

        return new User($id, $email, $name, $avatarUrl, $createdAt, $updatedAt, $isActive, $roles);
    }
}

class XmlSerializerRegistry
{
    private array $serializers = [];

    public function register(string $type, XmlSerializer $serializer): void
    {
        $this->serializers[$type] = $serializer;
    }

    public function getSerializer(string $type): ?XmlSerializer
    {
        return $this->serializers[$type] ?? null;
    }

    public function serialize(string $type, $entity): string
    {
        $serializer = $this->getSerializer($type);

        if ($serializer === null) {
            throw new \InvalidArgumentException("No serializer for type: {$type}");
        }

        return $serializer->serializeToString($entity);
    }
}
