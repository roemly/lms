<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2017 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

include(MODULES_DIR . DIRECTORY_SEPARATOR . 'invoicexajax.inc.php');

// Invoiceless liabilities: Zobowiazania/obciazenia na ktore nie zostala wystawiona faktura
function GetCustomerCovenants($customerid)
{
	global $DB;

	if(!$customerid) return NULL;
	
	return $DB->GetAll('SELECT c.time, c.value*-1 AS value, c.comment, c.taxid, 
			taxes.label AS tax, c.id AS cashid,
			ROUND(c.value / (taxes.value/100+1), 2)*-1 AS net
			FROM cash c
			LEFT JOIN taxes ON (c.taxid = taxes.id)
			WHERE c.customerid = ? AND c.docid = 0 AND c.value < 0
			ORDER BY time', array($customerid));
}

$taxeslist = $LMS->GetTaxes();

$SESSION->restore('invoicecontents', $contents);
$SESSION->restore('invoicecustomer', $customer);
$SESSION->restore('invoice', $invoice);
$SESSION->restore('invoicenewerror', $error);

$layout['pagetitle'] = !empty($invoice) && $invoice['proforma'] ? trans('New Pro Forma Invoice') : trans('New Invoice');

$itemdata = r_trim($_POST);

$action = isset($_GET['action']) ? $_GET['action'] : NULL;

switch($action)
{
	case 'init':

		unset($invoice);
		unset($contents);
		unset($customer);
		unset($error);

		// get default invoice's numberplanid and next number
		$currtime = time();
		$invoice['cdate'] = $currtime;
		$invoice['sdate'] = $currtime;
//		$invoice['paytype'] = ConfigHelper::getConfig('invoices.paytype');

		$invoice['proforma'] = isset($_GET['proforma']) ? 1 : 0;

		if(!empty($_GET['customerid']) && $LMS->CustomerExists($_GET['customerid']))
		{
			$customer = $LMS->GetCustomer($_GET['customerid'], true);

			$invoice['numberplanid'] = $DB->GetOne('SELECT n.id FROM numberplans n
				JOIN numberplanassignments a ON (n.id = a.planid)
				WHERE n.doctype = ? AND n.isdefault = 1 AND a.divisionid = ?',
				array($invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE, $customer['divisionid']));
		}

		if (isset($customer) && $customer['paytime'] != -1)
			$paytime = $customer['paytime'];
		elseif (($paytime = $DB->GetOne('SELECT inv_paytime FROM divisions 
			WHERE id = ?', array($customer['divisionid']))) === NULL)
			$paytime = ConfigHelper::getConfig('invoices.paytime');
		$invoice['deadline'] = $currtime + $paytime * 86400;

		if (empty($invoice['numberplanid']))
			$invoice['numberplanid'] = $DB->GetOne('SELECT id FROM numberplans
				WHERE doctype = ? AND isdefault = 1', array($invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE));
	break;

	case 'additem':

		unset($error);

		$itemdata['discount'] = str_replace(',', '.', $itemdata['discount']);
		$itemdata['pdiscount'] = 0;
		$itemdata['vdiscount'] = 0;
		if (preg_match('/^[0-9]+(\.[0-9]+)*$/', $itemdata['discount'])) {
			$itemdata['pdiscount'] = ($itemdata['discount_type'] == DISCOUNT_PERCENTAGE ? floatval($itemdata['discount']) : 0);
			$itemdata['vdiscount'] = ($itemdata['discount_type'] == DISCOUNT_AMOUNT ? floatval($itemdata['discount']) : 0);
		}
		if ($itemdata['pdiscount'] < 0 || $itemdata['pdiscount'] > 99.9 || $itemdata['vdiscount'] < 0)
			$error['discount'] = trans('Wrong discount value!');

		if ($error)
			break;

		foreach(array('count', 'pdiscount', 'vdiscount', 'valuenetto', 'valuebrutto') as $key)
			$itemdata[$key] = f_round($itemdata[$key]);

		if($itemdata['count'] > 0 && $itemdata['name'] != '')
		{
			$taxvalue = isset($itemdata['taxid']) ? $taxeslist[$itemdata['taxid']]['value'] : 0;
			if($itemdata['valuenetto'] != 0)
			{
				$itemdata['valuenetto'] = f_round(($itemdata['valuenetto'] - $itemdata['valuenetto'] * $itemdata['pdiscount'] / 100) - $itemdata['vdiscount']);
				$itemdata['valuebrutto'] = $itemdata['valuenetto'] * ($taxvalue / 100 + 1);
				$itemdata['s_valuebrutto'] = f_round(($itemdata['valuenetto'] * $itemdata['count']) * ($taxvalue / 100 + 1));
			}
			elseif($itemdata['valuebrutto'] != 0)
			{
				$itemdata['valuebrutto'] = f_round(($itemdata['valuebrutto'] - $itemdata['valuebrutto'] * $itemdata['pdiscount'] / 100) - $itemdata['vdiscount']);
				$itemdata['valuenetto'] = round($itemdata['valuebrutto'] / ($taxvalue / 100 + 1), 2);
				$itemdata['s_valuebrutto'] = f_round($itemdata['valuebrutto'] * $itemdata['count']);
			}

			// str_replace->f_round here is needed because of bug in some PHP versions
			$itemdata['s_valuenetto'] = f_round($itemdata['s_valuebrutto'] /  ($taxvalue / 100 + 1));
			$itemdata['valuenetto'] = f_round($itemdata['valuenetto']);
			$itemdata['count'] = f_round($itemdata['count']);
			$itemdata['discount'] = f_round($itemdata['discount']);
			$itemdata['pdiscount'] = f_round($itemdata['pdiscount']);
			$itemdata['vdiscount'] = f_round($itemdata['vdiscount']);
			$itemdata['tax'] = isset($itemdata['taxid']) ? $taxeslist[$itemdata['taxid']]['label'] : '';
			$itemdata['posuid'] = (string) getmicrotime();
			$contents[] = $itemdata;
		}
	break;

	case 'additemlist':

		if($marks = $_POST['marks'])
		{
			foreach($marks as $id)
			{
				$cash = $DB->GetRow('SELECT value, comment, taxid 
						    FROM cash WHERE id = ?', array($id));

				$itemdata['cashid'] = $id;
				$itemdata['name'] = $cash['comment'];
				$itemdata['taxid'] = $cash['taxid'];
				$itemdata['tax'] = isset($taxeslist[$itemdata['taxid']]) ? $taxeslist[$itemdata['taxid']]['label'] : '';
				$itemdata['discount'] = 0;
				$itemdata['pdiscount'] = 0;
				$itemdata['vdiscount'] = 0;
				$itemdata['count'] = f_round($_POST['l_count'][$id]);
				$itemdata['valuebrutto'] = f_round((-$cash['value'])/$itemdata['count']);
				$itemdata['s_valuebrutto'] = f_round(-$cash['value']);
				$itemdata['valuenetto'] = round($itemdata['valuebrutto'] / ((isset($taxeslist[$itemdata['taxid']]) ? $taxeslist[$itemdata['taxid']]['value'] : 0) / 100 + 1), 2);
				$itemdata['s_valuenetto'] = round($itemdata['s_valuebrutto'] / ((isset($taxeslist[$itemdata['taxid']]) ? $taxeslist[$itemdata['taxid']]['value'] : 0) / 100 + 1), 2);
				$itemdata['prodid'] = $_POST['l_prodid'][$id];
				$itemdata['jm'] = $_POST['l_jm'][$id];
				$itemdata['posuid'] = (string) (getmicrotime()+$id);
				$itemdata['tariffid'] = 0;
				$contents[] = $itemdata;
			}
		}
	break;

	case 'deletepos':
		if(sizeof($contents))
			foreach($contents as $idx => $row)
				if($row['posuid'] == $_GET['posuid']) 
					unset($contents[$idx]);
	break;

	case 'setcustomer':

		$customer_paytime = $customer['paytime'];

		unset($invoice); 
		unset($customer);
		unset($error);

		if($invoice = $_POST['invoice'])
			foreach($invoice as $key => $val)
				$invoice[$key] = $val;

		$invoice['customerid'] = $_POST['customerid'];

		$currtime = time();

		if($invoice['sdate'])
		{
			list($syear, $smonth, $sday) = explode('/', $invoice['sdate']);
			if(checkdate($smonth, $sday, $syear)) 
			{
				$invoice['sdate'] = mktime(date('G', $currtime), date('i', $currtime), date('s', $currtime), $smonth, $sday, $syear);
				$scurrmonth = $smonth;
			}
			else
			{
				$error['sdate'] = trans('Incorrect date format!');
				$invoice['sdate'] = $currtime;
				break;
			}
		}
		else
			$invoice['sdate'] = $currtime;

		if($invoice['cdate'])
		{
			list($year, $month, $day) = explode('/', $invoice['cdate']);
			if(checkdate($month, $day, $year)) 
			{
				$invoice['cdate'] = mktime(date('G', $currtime), date('i', $currtime), date('s', $currtime), $month, $day, $year);
				$currmonth = $month;
			}
			else
			{
				$error['cdate'] = trans('Incorrect date format!');
				$invoice['cdate'] = $currtime;
				break;
			}
		}

		if($invoice['cdate'] && !isset($invoice['cdatewarning']))
		{
			$maxdate = $DB->GetOne('SELECT MAX(cdate) FROM documents WHERE type = ? AND numberplanid = ?', 
					array($invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE, $invoice['numberplanid']));

			if($invoice['cdate'] < $maxdate)
			{
				$error['cdate'] = trans('Last date of invoice settlement is $a. If sure, you want to write invoice with date of $b, then click "Submit" again.',
					date('Y/m/d H:i', $maxdate), date('Y/m/d H:i', $invoice['cdate']));
				$invoice['cdatewarning'] = 1;
			}
		}
		elseif(!$invoice['cdate'])
			$invoice['cdate'] = $currtime;

		if ($invoice['deadline']) {
			list ($dyear, $dmonth, $dday) = explode('/', $invoice['deadline']);
			if (checkdate($dmonth, $dday, $dyear)) {
				$invoice['deadline'] = mktime(date('G', $currtime), date('i', $currtime), date('s', $currtime), $dmonth, $dday, $dyear);
				$dcurrmonth = $dmonth;
			} else {
				$error['deadline'] = trans('Incorrect date format!');
				$invoice['deadline'] = $currtime;
				break;
			}
		} else {
			if ($customer_paytime != -1)
				$paytime = $customer_paytime;
			elseif (($paytime = $DB->GetOne('SELECT inv_paytime FROM divisions
				WHERE id = ?', array($customer['divisionid']))) === NULL)
				$paytime = ConfigHelper::getConfig('invoices.paytime');
			$invoice['deadline'] = $invoice['cdate'] + $paytime * 86400;
		}

		if ($invoice['deadline'] < $invoice['cdate'])
			$error['deadline'] = trans('Deadline date should be later than consent date!');

		$cid = isset($_GET['customerid']) && $_GET['customerid'] != '' ? intval($_GET['customerid']) : intval($_POST['customerid']);

		if($invoice['number'])
		{
			if(!preg_match('/^[0-9]+$/', $invoice['number']))
				$error['number'] = trans('Invoice number must be integer!');
			elseif($LMS->DocumentExists(array(
					'number' => $invoice['number'],
					'doctype' => $invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE,
					'planid' => $invoice['numberplanid'],
					'cdate' => $invoice['cdate'],
					'customerid' => $cid
				)))
				$error['number'] = trans('Invoice number $a already exists!', $invoice['number']);
		}

		if(!isset($error))
		{
			if($LMS->CustomerExists($cid))
				$customer = $LMS->GetCustomer($cid, true);

			// finally check if selected customer can use selected numberplan
			if($invoice['numberplanid'] && isset($customer))
				if(!$DB->GetOne('SELECT 1 FROM numberplanassignments
					WHERE planid = ? AND divisionid = ?', array($invoice['numberplanid'], $customer['divisionid'])))
				{
					$error['number'] = trans('Selected numbering plan doesn\'t match customer\'s division!');
					unset($customer);
				}
		}
	break;

	case 'save':

		if (empty($contents) || empty($customer))
			break;

		unset($error);

		if ($invoice['deadline']) {
			$deadline = intval($invoice['deadline']);
			$cdate = intval($invoice['cdate']);
			if ($deadline < $cdate)
				break;
			$invoice['paytime'] = round(($deadline - $cdate) / 86400);
		} elseif ($customer['paytime'] != -1)
			$invoice['paytime'] = $customer['paytime'];
		elseif (($paytime = $DB->GetOne('SELECT inv_paytime FROM divisions 
			WHERE id = ?', array($customer['divisionid']))) !== NULL)
			$invoice['paytime'] = $paytime;
		else
			$invoice['paytime'] = ConfigHelper::getConfig('invoices.paytime');

		// set paytype
		if(empty($invoice['paytype']))
		{
			if($customer['paytype'])
				$invoice['paytype'] = $customer['paytype'];
			elseif($paytype = $DB->GetOne('SELECT inv_paytype FROM divisions 
				WHERE id = ?', array($customer['divisionid'])))
				$invoice['paytype'] = $paytype;
			else if (($paytype = intval(ConfigHelper::getConfig('invoices.paytype'))) && isset($PAYTYPES[$paytype]))
				$invoice['paytype'] = $paytype;
		    else
		        $error['paytype'] = trans('Default payment type not defined!');
		}

		if ($error)
			break;

		$DB->BeginTrans();
		$DB->LockTables(array('documents', 'cash', 'invoicecontents', 'numberplans', 'divisions', 'vdivisions'));

		if(!$invoice['number'])
			$invoice['number'] = $LMS->GetNewDocumentNumber(array(
				'doctype' => $invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE,
				'planid' => $invoice['numberplanid'],
				'cdate' => $invoice['cdate'],
				'customerid' => $customer['id'],
			));
		else {
			if(!preg_match('/^[0-9]+$/', $invoice['number']))
				$error['number'] = trans('Invoice number must be integer!');
			elseif($LMS->DocumentExists(array(
					'number' => $invoice['number'],
					'doctype' => $invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE,
					'planid' => $invoice['numberplanid'],
					'cdate' => $invoice['cdate'],
					'customerid' => $customer['id'],
				)))
				$error['number'] = trans('Invoice number $a already exists!', $invoice['number']);

			if($error) {
				$invoice['number'] = $LMS->GetNewDocumentNumber(array(
					'doctype' => $invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE, 
					'planid' => $invoice['numberplanid'],
					'cdate' => $invoice['cdate'],
					'customerid' => $customer['id'],
				));
				$error = null;
			}
		}

		$invoice['type'] = $invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE;

		$hook_data = array(
			'customer' => $customer,
			'contents' => $contents,
			'invoice' => $invoice,
		);
		$hook_data = $LMS->ExecuteHook('invoicenew_save_before_submit', $hook_data);

		$iid = $LMS->AddInvoice($hook_data);

		$hook_data['invoice']['id'] = $iid;
		$hook_data = $LMS->ExecuteHook('invoicenew_save_after_submit', $hook_data);

		$contents = $hook_data['contents'];
		$invoice = $hook_data['invoice'];

		// usuwamy wczesniejsze zobowiazania bez faktury
		foreach ($contents as $item)
			if (!empty($item['cashid']))
				$ids[] = intval($item['cashid']);

		if (!empty($ids)) {
			if ($SYSLOG)
				foreach ($ids as $cashid) {
					$args = array(
						SYSLOG::RES_CASH => $cashid,
						SYSLOG::RES_CUST => $customer['id'],
					);
					$SYSLOG->AddMessage(SYSLOG::RES_CASH, SYSLOG::OPER_DELETE, $args);
				}
			$DB->Execute('DELETE FROM cash WHERE id IN (' . implode(',', $ids) . ')');
		}

		$DB->UnLockTables();
		$DB->CommitTrans();

		$SESSION->remove('invoicecontents');
		$SESSION->remove('invoicecustomer');
		$SESSION->remove('invoice');
		$SESSION->remove('invoicenewerror');

		if(isset($_GET['print']))
			$SESSION->save('invoiceprint', array('invoice' => $iid,
				'original' => !empty($_GET['original']) ? 1 : 0,
				'copy' => !empty($_GET['copy']) ? 1 : 0));

		if (isset($_POST['reuse']))
			$SESSION->redirect('?m=invoicenew&action=init');
		else
			$SESSION->redirect('?' . $SESSION->get('backto'));
	break;
}

$SESSION->save('invoice', $invoice);
$SESSION->save('invoicecontents', isset($contents) ? $contents : NULL);
$SESSION->save('invoicecustomer', isset($customer) ? $customer : NULL);
$SESSION->save('invoicenewerror', isset($error) ? $error : NULL);


if($action)
{
	// redirect needed because we don't want to destroy contents of invoice in order of page refresh
	$SESSION->redirect('?m=invoicenew');
}

$covenantlist = array();
$list = GetCustomerCovenants($customer['id']);

if(isset($list))
	if($contents)
		foreach($list as $row)
		{
			$i = 0;
			foreach($contents as $item)
				if(isset($item['cashid']) && $row['cashid'] == $item['cashid'])
				{
					$i = 1;
					break;
				}
			if(!$i)
				$covenantlist[] = $row;
		}
	else
		$covenantlist = $list;

if (!ConfigHelper::checkConfig('phpui.big_networks'))
	$SMARTY->assign('customers', $LMS->GetCustomerNames());

if($newinvoice = $SESSION->get('invoiceprint'))
{
        $SMARTY->assign('newinvoice', $newinvoice);
        $SESSION->remove('invoiceprint');
}

$SMARTY->assign('covenantlist', $covenantlist);
$SMARTY->assign('error', $error);
$SMARTY->assign('contents', $contents);
$SMARTY->assign('customer', $customer);
$SMARTY->assign('invoice', $invoice);
$SMARTY->assign('tariffs', $LMS->GetTariffs());

$args = array(
	'doctype' => $invoice['proforma'] ? DOC_INVOICE_PRO : DOC_INVOICE,
	'cdate' => date('Y/m', $invoice['cdate']),
);
if (isset($customer)) {
	$args['customerid'] = $customer['id'];
	$args['division'] = $DB->GetOne('SELECT divisionid FROM customers WHERE id = ?', array($customer['id']));
} else
	$args['customerid'] = null;
$SMARTY->assign('numberplanlist', $LMS->GetNumberPlans($args));

$SMARTY->assign('taxeslist', $taxeslist);
$SMARTY->display('invoice/invoicenew.html');

?>
