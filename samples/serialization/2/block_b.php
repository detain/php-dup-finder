<?php

declare(strict_types=1);

namespace App\Xml;

class ProductXmlSerializer
{
    private string $rootElement = 'product';
    private string $namespace = 'http://api.example.com/products';

    public function serialize(Product $product): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS($this->namespace, $this->rootElement);
        $doc->appendChild($root);

        $root->appendChild($this->createElement($doc, 'id', $product->getId()));
        $root->appendChild($this->createElement($doc, 'name', $product->getName()));

        if ($product->getDescription() !== null) {
            $root->appendChild($this->createElement($doc, 'description', $product->getDescription()));
        }

        $priceElement = $doc->createElement('price');
        $priceElement->appendChild($this->createElement($doc, 'amount', (string)$product->getPrice()));
        $priceElement->appendChild($this->createElement($doc, 'currency', $product->getCurrency()));
        $root->appendChild($priceElement);

        $root->appendChild($this->createElement($doc, 'categoryId', $product->getCategoryId()));

        if ($product->getImageUrl() !== null) {
            $root->appendChild($this->createElement($doc, 'imageUrl', $product->getImageUrl()));
        }

        $root->appendChild($this->createElement($doc, 'stockQuantity', (string)$product->getStockQuantity()));
        $root->appendChild($this->createElement($doc, 'isAvailable', $product->isAvailable() ? 'true' : 'false'));

        $root->appendChild($this->createElement($doc, 'createdAt', $product->getCreatedAt()->format('c')));

        if ($product->getUpdatedAt() !== null) {
            $root->appendChild($this->createElement($doc, 'updatedAt', $product->getUpdatedAt()->format('c')));
        }

        $tagsElement = $doc->createElement('tags');
        foreach ($product->getTags() as $tag) {
            $tagsElement->appendChild($this->createElement($doc, 'tag', $tag));
        }
        $root->appendChild($tagsElement);

        return $doc;
    }

    public function serializeToString(Product $product): string
    {
        return $this->serialize($product)->saveXML();
    }

    public function deserialize(\DOMDocument $doc): Product
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ns', $this->namespace);

        $root = $xpath->query('//ns:product')->item(0);

        if ($root === null) {
            throw new \InvalidArgumentException('Invalid product XML document');
        }

        $id = $this->getElementValue($xpath, $root, 'id');
        $name = $this->getElementValue($xpath, $root, 'name');
        $description = $this->getElementValue($xpath, $root, 'description');

        $amount = $this->getElementValue($xpath, $xpath->query('ns:price/ns:amount', $root)->item(0));
        $currency = $this->getElementValue($xpath, $xpath->query('ns:price/ns:currency', $root)->item(0));

        $categoryId = $this->getElementValue($xpath, $root, 'categoryId');
        $imageUrl = $this->getElementValue($xpath, $root, 'imageUrl');
        $stockQuantity = (int)$this->getElementValue($xpath, $root, 'stockQuantity');
        $isAvailable = $this->getElementValue($xpath, $root, 'isAvailable') === 'true';

        $createdAt = new DateTimeImmutable($this->getElementValue($xpath, $root, 'createdAt'));
        $updatedAtStr = $this->getElementValue($xpath, $root, 'updatedAt');
        $updatedAt = $updatedAtStr ? new DateTimeImmutable($updatedAtStr) : null;

        $tags = [];
        $tagNodes = $xpath->query('ns:tags/ns:tag', $root);
        foreach ($tagNodes as $tagNode) {
            $tags[] = $tagNode->textContent;
        }

        return new Product(
            $id,
            $name,
            $description,
            (float)$amount,
            $currency,
            $categoryId,
            $imageUrl,
            $stockQuantity,
            $isAvailable,
            $createdAt,
            $updatedAt,
            $tags
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
