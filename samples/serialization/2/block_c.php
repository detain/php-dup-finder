<?php

declare(strict_types=1);

namespace App\Xml;

class OrderXmlSerializer
{
    private string $rootElement = 'order';
    private string $namespace = 'http://api.example.com/orders';

    public function serialize(Order $order): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS($this->namespace, $this->rootElement);
        $doc->appendChild($root);

        $root->appendChild($this->createElement($doc, 'id', $order->getId()));
        $root->appendChild($this->createElement($doc, 'userId', $order->getUserId()));

        $itemsElement = $doc->createElement('items');
        foreach ($order->getItems() as $item) {
            $itemElement = $doc->createElement('item');
            $itemElement->appendChild($this->createElement($doc, 'productId', $item['product_id']));
            $itemElement->appendChild($this->createElement($doc, 'quantity', (string)$item['quantity']));
            $itemElement->appendChild($this->createElement($doc, 'unitPrice', (string)$item['unit_price']));
            $itemsElement->appendChild($itemElement);
        }
        $root->appendChild($itemsElement);

        $totalElement = $doc->createElement('total');
        $totalElement->appendChild($this->createElement($doc, 'amount', (string)$order->getTotalAmount()));
        $totalElement->appendChild($this->createElement($doc, 'currency', $order->getCurrency()));
        $root->appendChild($totalElement);

        $root->appendChild($this->createElement($doc, 'status', $order->getStatus()));

        if ($order->getShippingAddress() !== null) {
            $root->appendChild($this->createElement($doc, 'shippingAddress', $order->getShippingAddress()));
        }

        if ($order->getBillingAddress() !== null) {
            $root->appendChild($this->createElement($doc, 'billingAddress', $order->getBillingAddress()));
        }

        $root->appendChild($this->createElement($doc, 'createdAt', $order->getCreatedAt()->format('c')));

        if ($order->getUpdatedAt() !== null) {
            $root->appendChild($this->createElement($doc, 'updatedAt', $order->getUpdatedAt()->format('c')));
        }

        if ($order->getShippedAt() !== null) {
            $root->appendChild($this->createElement($doc, 'shippedAt', $order->getShippedAt()->format('c')));
        }

        return $doc;
    }

    public function serializeToString(Order $order): string
    {
        return $this->serialize($order)->saveXML();
    }

    public function deserialize(\DOMDocument $doc): Order
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ns', $this->namespace);

        $root = $xpath->query('//ns:order')->item(0);

        if ($root === null) {
            throw new \InvalidArgumentException('Invalid order XML document');
        }

        $id = $this->getElementValue($xpath, $root, 'id');
        $userId = $this->getElementValue($xpath, $root, 'userId');

        $items = [];
        $itemNodes = $xpath->query('ns:items/ns:item', $root);
        foreach ($itemNodes as $itemNode) {
            $items[] = [
                'product_id' => $this->getElementValue($xpath, $itemNode, 'productId'),
                'quantity' => (int)$this->getElementValue($xpath, $itemNode, 'quantity'),
                'unit_price' => (float)$this->getElementValue($xpath, $itemNode, 'unitPrice')
            ];
        }

        $totalAmount = (float)$this->getElementValue($xpath, $xpath->query('ns:total/ns:amount', $root)->item(0));
        $currency = $this->getElementValue($xpath, $xpath->query('ns:total/ns:currency', $root)->item(0));

        $status = $this->getElementValue($xpath, $root, 'status');
        $shippingAddress = $this->getElementValue($xpath, $root, 'shippingAddress');
        $billingAddress = $this->getElementValue($xpath, $root, 'billingAddress');

        $createdAt = new DateTimeImmutable($this->getElementValue($xpath, $root, 'createdAt'));
        $updatedAtStr = $this->getElementValue($xpath, $root, 'updatedAt');
        $updatedAt = $updatedAtStr ? new DateTimeImmutable($updatedAtStr) : null;
        $shippedAtStr = $this->getElementValue($xpath, $root, 'shippedAt');
        $shippedAt = $shippedAtStr ? new DateTimeImmutable($shippedAtStr) : null;

        return new Order(
            $id,
            $userId,
            $items,
            $totalAmount,
            $currency,
            $status,
            $shippingAddress,
            $billingAddress,
            $createdAt,
            $updatedAt,
            $shippedAt
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
