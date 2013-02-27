<?php

include ("../../../dbconnect.php");
include ("../../../includes/functions.php");
include ("../../../includes/gatewayfunctions.php");
include ("../../../includes/invoicefunctions.php");

require_once ('../libwebtopay/WebToPay.php');

$gatewaymodule = "paysera";
$GATEWAY = getGatewayVariables($gatewaymodule);

if (!$GATEWAY["type"])
	die("Module Not Activated");



try {
	$response = WebToPay::checkResponse($_REQUEST, array(
	'projectid' => $GATEWAY['projectID'], 
	'sign_password' => $GATEWAY['projectPass'],
	 ));

	$orderid = mysql_escape_string($response['orderid']);

	$invoiceid = checkCbInvoiceID($orderid, $GATEWAY["paysera"]);

	if (isset($_REQUEST['accepturl'])) {
		$invoiceid = checkCbInvoiceID($orderid, $GATEWAY["paysera"]);

		logTransaction($GATEWAY["name"], $_REQUEST, "Successful");
		addInvoicePayment($invoiceid, null, null, null, $gatewaymodule);
		$redirectURL = $GATEWAY['systemurl'] . '/viewinvoice.php?id=' . $invoiceid;
		header("Location: " . $redirectURL);

	} else {

		//Admin username for API
		if (!$GATEWAY["adminusername"]) {
		die("Admin login name not set");
		} else {
		$adminusername = $GATEWAY["adminusername"];
		}

		$orderAmount = '';
		$result = mysql_query("SELECT total FROM tblinvoices WHERE id = " . $orderid);
		$orderAmount = mysql_result($result, 0);

		if (isset($GATEWAY['convertto'])) {
			$result = mysql_query("SELECT rate FROM tblcurrencies WHERE code = '" . $response['currency'] . "'");
			$rate = '';
			while ($row = mysql_fetch_array($result)) {
				$rate = $row['rate'];
			}
			$orderAmount *= $rate;
		}
		if ($response['status'] == 1) {

			//Check amount
			if (round($orderAmount * 100) > $response['amount']) {
				logTransaction($GATEWAY["paymentmethod"], $_REQUEST, "[CALLBACK]Unsuccessful, bad amount");
				exit('Bad amount!');
			}

			//Get real invoice id's if there are multiple invoices paid with one invoice. Check for duplicate callback.
			$query = mysql_query("
			SELECT i.relid, o.status , i.description
			FROM tblinvoiceitems AS i
				INNER JOIN tblorders AS o ON (i.relid = o.invoiceid)
			WHERE i.invoiceid = " . $orderid);

			while ($result = mysql_fetch_array($query)) {
				if ($result['status'] == 'Pending') {

					$value['status'] = "Paid";
					$value['invoiceid'] = $orderid;
					localAPI("updateinvoice", $value, $adminusername);

					$type = localAPI("getinvoice", $value, $adminusername);
					if ($type['items']['item']['0']['type'] == "Invoice") {
						mysql_query("UPDATE tblorders SET status = 'Processed' WHERE invoiceid = " . $result['relid']);

						//In case if user closed redirect from paysera to accept page but payment recieved
						mysql_query("UPDATE tblinvoiceitems SET amount = '0.00' WHERE invoiceid = " . $result['relid']);
						$value['invoiceid'] = $result['relid'];
						localAPI("updateinvoice", $value, $adminusername);
					} else {
						mysql_query("UPDATE tblorders SET status = 'Processed' WHERE invoiceid = " . $orderid);
					}
				} else {
					exit("OK");
				}
			}
		}

	}
	logTransaction($GATEWAY["name"], $_REQUEST, "[CALLBACK]Successful");
	exit('OK');
} catch (Exception $e) {
	logTransaction($GATEWAY["name"], $_REQUEST, "[CALLBACK]Unsuccessful");
	exit(get_class($e) . ': ' . $e -> getMessage());
}
