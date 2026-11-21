<?php
declare(strict_types=1);

// tests/Fixtures/micro-fixture/preamble_4.php — reconstructed per
// improve_logic.md Appendix A: five classes whose go() methods share a
// 9-line preamble and differ afterwards. Siblings alter only the tail.

final class ShipmentListService
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

        $q = $db->query("select carrier,tracking from order_shipments where order_id = " . (int) $id);
        $ships = []; while ($db->next_record()) { $ships[] = [$db->f("carrier"), $db->f("tracking")]; }
        return json_response(["shipments" => $ships]);
    }
}
