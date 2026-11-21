<?php
declare(strict_types=1);

// tests/Fixtures/micro-fixture/preamble_3.php — reconstructed per
// improve_logic.md Appendix A: five classes whose go() methods share a
// 9-line preamble and differ afterwards. Siblings alter only the tail.

final class InvoiceTotalService
{
    public function go($id, $module)
    {
        $settings = get_module_settings($module);
        $db = get_module_db($module);
        if ($id === false) {
            $id = $_GET['id'];
        }
        $id = resolve_service_id($id, $module);
        $serviceInfo = get_service($id, $module);
        if ($serviceInfo === false) {
            return json_error('Invalid Order Passed', 404);
        }
        if ($serviceInfo[$settings['PREFIX'] . '_status'] !== 'active') {
            return json_error('Order is not active', 409);
        }

        $sum = $db->query("select sum(total) from order_invoices where order_id = " . (int) $id);
        return json_response(["total" => (float) $db->f(0)]);
    }
}
