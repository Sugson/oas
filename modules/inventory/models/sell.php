<?php
/**
 * @filesource modules/inventory/models/sell.php
 *
 * @see http://www.kotchasan.com/
 *
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */

namespace Inventory\Sell;

use Gcms\Login;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * module=inventory-sell.
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * บันทึกข้อมูลการขาย.
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = array();
        // session, token, can_sell, ไม่ใช่สมาชิกตัวอย่าง
        if ($request->initSession() && $request->isSafe() && $login = Login::isMember()) {
            if (Login::checkPermission($login, 'can_sell') && Login::notDemoMode($login)) {
                $order = array(
                    'order_no' => $request->post('order_no')->topic(),
                    'customer_id' => $request->post('customer_id')->toInt(),
                    'member_id' => $login['id'],
                    'comment' => $request->post('comment')->textarea(),
                    'order_date' => $request->post('order_date')->date(),
                    'discount_percent' => $request->post('discount_percent')->toDouble(),
                    'discount' => $request->post('total_discount')->toDouble(),
                    'tax' => $request->post('tax_total')->toDouble(),
                    'vat' => $request->post('vat_total')->toDouble(),
                    'total' => $request->post('amount')->toDouble(),
                    'vat_status' => $request->post('vat_status')->toInt(),
                    'tax_status' => $request->post('tax_status')->toInt(),
                    'status' => $request->post('status')->toInt(),
                );
                $order_id = $request->post('order_id')->toInt();
                // Model
                $model = new static();
                // ชื่อตาราง
                $table_orders = $model->getTableName('orders');
                $table_stock = $model->getTableName('stock');
                // ตรวจสอบรายการ order ที่เลือก
                $orders = \Inventory\Order\Model::get($order_id, 'OUT', $order['status']);
                if (!$orders) {
                    // ไม่พบข้อมูลที่แก้ไข
                    $ret['alert'] = Language::get('Sorry, Item not found It&#39;s may be deleted');
                } else {
                    // สินค้าที่เลือก
                    $datas = array(
                        'quantity' => $request->post('quantity', array())->toInt(),
                        'topic' => $request->post('topic', array())->topic(),
                        'price' => $request->post('price', array())->toDouble(),
                        'discount' => $request->post('discount', array())->toDouble(),
                        'total' => $request->post('total', array())->toDouble(),
                        'vat' => $request->post('vat', array())->toDouble(),
                        'id' => $request->post('id', array())->toInt(),
                    );
                    $stock = array();
                    foreach ($datas['topic'] as $key => $value) {
                        if ($value != '') {
                            $stock[] = array(
                                'quantity' => $datas['quantity'][$key],
                                'topic' => $datas['topic'][$key],
                                'price' => $datas['price'][$key],
                                'discount' => $datas['discount'][$key],
                                'total' => $datas['total'][$key],
                                'vat' => empty($datas['vat'][$key]) ? 0 : $datas['vat'][$key],
                                'product_id' => $datas['id'][$key],
                            );
                        }
                    }
                    if (empty($stock)) {
                        // ไม่ได้เลือกสินค้า
                        $ret['ret_topic_'.$key] = 'Please fill in';
                    }
                    if (empty($ret)) {
                        // save order
                        if ($order['order_no'] == '') {
                            // สร้างเลข running number
                            $order['order_no'] = \Inventory\Number\Model::get($order_id, 'billing_no', $table_orders, 'order_no');
                        } else {
                            // ตรวจสอบ order_no ซ้ำ
                            $search = $model->db()->first($table_orders, array(
                                array('order_no', $order['order_no']),
                            ));
                            if ($search !== false && $order_id != $search->id) {
                                $ret['ret_order_no'] = Language::replace('This :name already exist', array(':name' => Language::get('Order No.')));
                            }
                        }
                    }
                    if (empty($ret)) {
                        if ($order_id > 0) {
                            // แก้ไข
                            $model->db()->createQuery()
                                ->update('orders')
                                ->set($order)
                                ->where(array(
                                    array('id', $order_id),
                                ))
                                ->execute();
                        } else {
                            // ใหม่
                            $order_id = $model->db()->getNextId($table_orders);
                            $order['id'] = $order_id;
                            $order['stock_status'] = 'OUT';
                            $model->db()->insert($table_orders, $order);
                        }
                        // ลบ stock เก่า (ถ้ามี)
                        $model->db()->delete($table_stock, array(
                            array('order_id', $order_id),
                        ), 0);
                        // save stock
                        foreach ($stock as $save) {
                            $save['member_id'] = $order['member_id'];
                            $save['order_id'] = $order_id;
                            $save['status'] = 'OUT';
                            $save['create_date'] = $order['order_date'];
                            $model->db()->insert($table_stock, $save);
                        }
                        // คืนค่า
                        $ret['alert'] = Language::get('Saved successfully');
                        $save_and_create = $request->post('save_and_create')->toInt();
                        if ($save_and_create == 1) {
                            $ret['location'] = 'reload';
                        } else {
                            $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'inventory-outward', 'status' => $order['status'], 'id' => null));
                        }
                        // save cookie
                        setcookie('sell_save_and_create', $save_and_create, time() + 2592000, '/', HOST, HTTPS, true);
                        setcookie('sell_vat_status', $order['vat_status'], time() + 2592000, '/', HOST, HTTPS, true);
                        // เคลียร์
                        $request->removeToken();
                    }
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่าเป็น JSON
        echo json_encode($ret);
    }
}
