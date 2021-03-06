<?php

/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.3                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2013                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2013
 * $Id$
 *
 */

require_once 'CRM/CiviDiscount/DAO/Item.php';

class CRM_CiviDiscount_BAO_Item extends CRM_CiviDiscount_DAO_Item {

  /**
   * class constructor
   */
  function __construct() {
    parent::__construct();
  }

  /**
   * Takes an associative array and creates a discount item
   *
   * This function extracts all the params it needs to initialize the created
   * discount item. The params array could contain additional unused name/value
   * pairs
   *
   * @param array  $params (reference ) an assoc array of name/value pairs
   *
   * @return object CRM_CiviDiscount_BAO_Item object
   * @access public
   * @static
   */
  static function add(&$params) {

    $item = new CRM_CiviDiscount_DAO_Item();
    $item->code = $params['code'];
    $item->description = $params['description'];
    $item->amount = $params['amount'];
    $item->amount_type = $params['amount_type'];
    $item->count_max = $params['count_max'];
    $item->discount_msg = $params['discount_msg'];
    $item->filters = json_encode($params['filters']);

    foreach ($params['multi_valued'] as $mv => $dontCare) {
      if (!empty($params[$mv])) {
        $item->$mv =
          CRM_Core_DAO::VALUE_SEPARATOR .
          implode(CRM_Core_DAO::VALUE_SEPARATOR, array_values($params[$mv])) .
          CRM_Core_DAO::VALUE_SEPARATOR;
      }
      else {
        $item->$mv = 'null';
      }
    }

    if (! empty($params['id'])) {
      $item->id = $params['id'];
    }

    $item->is_active = CRM_Utils_Array::value('is_active', $params) ? 1 : 0;
    $item->discount_msg_enabled = CRM_Utils_Array::value('discount_msg_enabled', $params) ? 1 : 0;

    if (! empty($params['active_on'])) {
      $item->active_on = CRM_Utils_Date::processDate($params['active_on']);
    }
    else {
      $item->active_on = 'null';
    }

    if (! empty($params['expire_on'])) {
      $item->expire_on = CRM_Utils_Date::processDate($params['expire_on']);
    }
    else {
      $item->expire_on = 'null';
    }

    if (! empty($params['organization_id'])) {
      $item->organization_id = $params['organization_id'];
    }
    else {
      $item->organization_id = 'null';
    }

    $id = empty($params['id']) ? NULL : $params['id'];
    $op = $id ? 'edit' : 'create';
    CRM_Utils_Hook::pre($op, 'CiviDiscount', $id, $params);
    $item->save();
    CRM_Utils_Hook::post($op, 'CiviDiscount', $item->id, $item);

    return $item;
  }

  /**
   * Takes a bunch of params that are needed to match certain criteria and
   * retrieves the relevant objects. Typically the valid params are only
   * contact_id. We'll tweak this function to be more full featured over a period
   * of time. This is the inverse function of create. It also stores all the retrieved
   * values in the default array
   *
   * @param array $params   (reference) an assoc array of name/value pairs
   * @param array $defaults (reference) an assoc array to hold the flattened values
   *
   * @return object CRM_CiviDiscount_BAO_Item object on success, null otherwise
   * @access public
   * @static
   */
  static function retrieve(&$params, &$defaults) {
    $item = new CRM_CiviDiscount_DAO_Item();
    $item->copyValues($params);
    if ($item->find(true)) {
      CRM_Core_DAO::storeValues($item, $defaults);
      return $item;
    }
    return null;
  }

  static function getValidDiscounts() {
    $discounts = array();

    $sql = "
SELECT  id,
    code,
    description,
    amount,
    amount_type,
    events,
    pricesets,
    memberships,
    autodiscount,
    expire_on,
    active_on,
    is_active,
    discount_msg_enabled,
    discount_msg,
    count_use,
    count_max,
    filters
FROM    cividiscount_item
";
    $dao = CRM_Core_DAO::executeQuery($sql, array());
    while ($dao->fetch()) {
      $a = (array) $dao;
      if (CRM_CiviDiscount_BAO_Item::isValid($a)) {
        $discounts[$a['code']] = $a;
      }
    }
   $filters = json_decode($dao->filters, TRUE);
    // Expand set-valued fields.
    $fields = array('events' => 'event', 'pricesets' => 'price_set', 'memberships' => 'membership', 'autodiscount' => NULL);
    foreach ($discounts as &$discount) {
      foreach ($fields as $field => $entity) {
        if(is_null($discount[$field])) {
          $items = array();
        }
        else {
          $items = explode(CRM_Core_DAO::VALUE_SEPARATOR, trim($discount[$field], CRM_Core_DAO::VALUE_SEPARATOR));
        }
        if(!empty($items) && $entity) {
          if(!isset($filters[$entity])) {
            $filters[$entity] = array();
          }
          //0 indicates 'any' so for 0 we construct an empty filter - otherwise we add a limit by id clause
          //note that this may be combined with stored filters e.g. 'event_type_id'
          if(!in_array(0, $items)) {
            $filters[$entity]['id'] = array('IN' => $items);
          }
        }
        $discount[$field] = !empty($items) ? array_combine($items, $items) : array();
      }
    }
    $discount['filters'] = empty($filters) ? array() : $filters;
    return $discounts;
  }

  /**
   * update the is_active flag in the db
   *
   * @param int      $id        id of the database record
   * @param boolean  $is_active value we want to set the is_active field
   *
   * @return Object             DAO object on sucess, null otherwise
   *
   * @access public
   * @static
   */
  static function setIsActive($id, $is_active) {
    return CRM_Core_DAO::setFieldValue('CRM_CiviDiscount_DAO_Item', $id, 'is_active', $is_active);
  }


  static function incrementUsage($id) {
    $sql = "UPDATE cividiscount_item SET count_use = count_use+1 WHERE id = {$id}";
    return CRM_Core_DAO::executeQuery($sql);
  }

  static function decrementUsage($id) {
    $sql = "UPDATE cividiscount_item SET count_use = count_use-1 WHERE id = {$id}";
    return CRM_Core_DAO::executeQuery($sql);
  }

  static function isValid($code) {
    if (!CRM_CiviDiscount_BAO_Item::isExpired($code) &&
      CRM_CiviDiscount_BAO_Item::isActive($code) &&
      CRM_CiviDiscount_BAO_Item::isEnabled($code) &&
      ($code['count_max'] == 0 || $code['count_max'] > $code['count_use'])
    ) {
      return TRUE;
    }

    return FALSE;
  }

  static function isExpired($code) {
    if (empty($code['expire_on'])) {
      return FALSE;
    }

    $time = CRM_Utils_Date::getToday(null, 'Y-m-d H:i:s');

    if (strtotime($time) > abs(strtotime($code['expire_on']))) {
      return TRUE;
    }

    return FALSE;
  }


  static function isActive($code) {
    if (empty($code['active_on'])) {
      return TRUE;
    }

    $time = CRM_Utils_Date::getToday(null, 'Y-m-d H:i:s');

    if (strtotime($time) > abs(strtotime($code['active_on']))) {
      return TRUE;
    }

    return FALSE;
  }


  static function isEnabled($code) {
    if ($code['is_active'] == 1) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Function to delete discount codes
   *
   * @param  int  $itemID     ID of the discount code to be deleted.
   *
   * @access public
   * @static
   * @return true on success else false
   */
  static function del($itemID) {
    $item = new CRM_CiviDiscount_DAO_Item();
    $item->id = $itemID;

    if ($item->find(TRUE)) {
      CRM_Utils_Hook::pre('delete', 'CiviDiscount', $item->id, $item);
      $item->delete();
      CRM_Utils_Hook::post('delete', 'CiviDiscount', $item->id, $item);

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Function to copy discount codes
   *
   * @param  int  $itemID     ID of the discount code to be copied.
   *
   * @access public
   * @static
   * @return true on success else false
   */
  static function copy($itemID, $params, $newCode) {
    $item = new CRM_CiviDiscount_DAO_Item();
    $item->id = $itemID;

    if ($item->find(TRUE)) {
      unset($item->id);
      $item->count_use = 0;
      $item->code = $newCode;
      if (isset($item->description) && $item->description != '') {
        $item->description = 'Copy of ' . $item->description;
      }

      CRM_Utils_Hook::pre('create', 'CiviDiscount', null, $params);
      $item->save();
      CRM_Utils_Hook::post('create', 'CiviDiscount', $item->id, $item);
      return TRUE;
    }

    return FALSE;
  }
}
