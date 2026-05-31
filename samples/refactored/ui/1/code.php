<?php
// app/View/AddressFieldset.php
namespace App\View;

final class AddressFieldset
{
    /**
     * @param array<string,string>      $address
     * @param array<string,string>      $errors
     */
    public static function render(
        string $idPrefix,
        string $heading,
        string $action,
        string $submitLabel,
        array $address,
        array $errors = []
    ): string {
        $field = static function (string $name, string $label, string $value, ?string $err, string $prefix, array $extra = []): string {
            $id    = $prefix . '_' . $name;
            $maxlen = $extra['maxlength'] ?? null;
            $attrs = $maxlen ? sprintf(' maxlength="%d"', (int) $maxlen) : '';
            $row   = sprintf(
                '<div class="form-row"><label for="%s">%s</label>'
                . '<input id="%s" name="%s" type="text"%s value="%s" aria-invalid="%s">',
                $id, htmlspecialchars($label), $id, $name, $attrs,
                htmlspecialchars($value), $err !== null ? 'true' : 'false'
            );
            if ($err !== null) {
                $row .= '<span class="err">' . htmlspecialchars($err) . '</span>';
            }
            return $row . '</div>';
        };

        $html  = sprintf('<form method="post" action="%s" class="card p-4"><h3>%s</h3>',
                         htmlspecialchars($action), htmlspecialchars($heading));
        $html .= '<fieldset class="address-fieldset">';
        $html .= $field('street',  'Street',  $address['street'],  $errors['street']  ?? null, $idPrefix);
        $html .= $field('city',    'City',    $address['city'],    $errors['city']    ?? null, $idPrefix);
        $html .= '<div class="form-row split">';
        $html .= $field('state',   'State',   $address['state'],   $errors['state']   ?? null, $idPrefix, ['maxlength' => 2]);
        $html .= $field('zip',     'ZIP',     $address['zip'],     $errors['zip']     ?? null, $idPrefix);
        $html .= '</div>';
        $html .= $field('country', 'Country', $address['country'], $errors['country'] ?? null, $idPrefix);
        $html .= '</fieldset>';
        $html .= sprintf('<button type="submit" class="btn-primary">%s</button></form>',
                         htmlspecialchars($submitLabel));
        return $html;
    }
}

// Call sites collapse to:
// AddressFieldset::render('b', 'Billing Address',  '/billing/save',  'Save Billing',  $billing, $errors);
// AddressFieldset::render('s', 'Shipping Address', '/shipping/save', 'Save Shipping', $ship,    $errors);
// AddressFieldset::render('v', 'Vendor Address',   '/vendor/address','Save Vendor Address', $vendor, $errors);
