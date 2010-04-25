<?php

class exact
{
	var $settings;
	
	function exact() {
		$this->settings = $this->Settings();
	}

	function Settings()
	{
		$settings = array();
		
		$settings['name'] = 'E-xact';
		$settings['class_name'] = 'exact';
		$settings['description'] = 'E-xact from VersaPay is the perfect gateway for both Canadian and American merchants.';
		$settings['is_preferred'] = 1;
		$settings['setup_fee'] = '$149.99';
		$settings['monthly_fee'] = '$29.99';
		$settings['transaction_fee'] = '$0.25';
		$settings['purchase_link'] = 'http://www.opengateway.net/gateways/exact';
		$settings['allows_updates'] = 1;
		$settings['allows_refunds'] = 1;
		$settings['requires_customer_information'] = 0;
		$settings['requires_customer_ip'] = 0;
		$settings['required_fields'] = array('enabled',
											 'terminal_id',
											 'password',
											 'accept_visa',
											 'accept_mc',
											 'accept_discover',
											 'accept_dc',
											 'accept_amex');
											 
		$settings['field_details'] = array(
										'enabled' => array(
														'text' => 'Enable this gateway?',
														'type' => 'radio',
														'options' => array(
																		'1' => 'Enabled',
																		'0' => 'Disabled')
														),
										'terminal_id' => array(
														'text' => 'Terminal ID',
														'type' => 'text'
														),
										'password' => array(
														'text' => 'Password',
														'type' => 'text'
														),
										'accept_visa' => array(
														'text' => 'Accept VISA?',
														'type' => 'radio',
														'options' => array(
																		'1' => 'Yes',
																		'0' => 'No'
																	)
														),
										'accept_mc' => array(
														'text' => 'Accept MasterCard?',
														'type' => 'radio',
														'options' => array(
																		'1' => 'Yes',
																		'0' => 'No'
																	)
														),
										'accept_discover' => array(
														'text' => 'Accept Discover?',
														'type' => 'radio',
														'options' => array(
																		'1' => 'Yes',
																		'0' => 'No'
																	)
														),
										'accept_dc' => array(
														'text' => 'Accept Diner\'s Club?',
														'type' => 'radio',
														'options' => array(
																		'1' => 'Yes',
																		'0' => 'No'
																	)
														),
										'accept_amex' => array(
														'text' => 'Accept American Express?',
														'type' => 'radio',
														'options' => array(
																		'1' => 'Yes',
																		'0' => 'No'
																	)
														)
											);
		
		return $settings;
	}
	
	function TestConnection($client_id, $gateway) 
	{
		$post_url = $gateway['url_live'];
			
		$trxnProperties = array(
					'ExactID'			=> $gateway['terminal_id'],	
			  		'Password'			=> $gateway['password'],
					'Transaction_Type'  => '00',
				 	'Card_Number' 		=> '4222222222222222',
					'Expiry_Date'		=> '1099',
					'CVD_Presence_Ind' 	=> '9',
					'DollarAmount' 		=> 1
		  		);

		$trxnProperties = $this->CompleteArray($trxnProperties);   		
		  		
		$trxnResult = $this->Process($trxnProperties, $post_url, 0);
		
		if($trxnResult->EXact_Resp_Code == '00'){
			return TRUE;
		} else {
			return FALSE;
		}
		
	}
	
	function Charge($client_id, $order_id, $gateway, $customer, $amount, $credit_card)
	{			
		$CI =& get_instance();
		
		$post_url = $gateway['url_live'];
			
		$transaction = array(
					'ExactID'			=> $gateway['terminal_id'],	
			  		'Password'			=> $gateway['password'],
					'Transaction_Type'  => '00',
				 	'Card_Number' 		=> $credit_card['card_num'],
					'Expiry_Date'		=> $credit_card['exp_month'] . substr($credit_card['exp_year'],-2,2),
					'CVD_Presence_Ind' 	=> (empty($credit_card['cvv'])) ? '9' : '1',
					'Customer_Ref' 		=> $order_id,
					'DollarAmount' 		=> $amount
		  		);
		
		if (isset($credit_card['cvv'])) {
			$transaction['VerificationStr1'] = $credit_card['cvv'];
		}  
		
		if (isset($customer['customer_id'])) {
			// build customer's name from customer array
			$transaction['CardHoldersName'] = $customer['first_name'].' '.$customer['last_name'];
		}
		else {
			// automatically get customer's name from credit card
			$name = explode(' ', $credit_card['card_name']);
			$transaction['CardHoldersName'] = $name[0] . ' ' . $name[1];
		}
		
		if (isset($customer['ip_address']) and !empty($customer['ip_address'])) {
			$transaction['Client_IP'] = $customer['ip_address'];
		}
		  
		$transaction = $this->CompleteArray($transaction); 
		  
		$transaction_result = $this->Process($transaction, $post_url, $order_id);
		
		if($transaction_result->EXact_Resp_Code == '00'){
			$CI->load->model('order_authorization_model');
			$CI->order_authorization_model->SaveAuthorization($order_id, $transaction_result->Transaction_Tag);
			$response_array = array('charge_id' => $order_id);
			$response = $CI->response->TransactionResponse(1, $response_array);
		} else {
			$response_array = array('reason' => $transaction_result->EXact_Message);
			$response = $CI->response->TransactionResponse(2, $response_array);
		}
		
		return $response;
	}
	
	function Recur ($client_id, $gateway, $customer, $amount, $start_date, $end_date, $interval, $credit_card, $subscription_id, $total_occurrences = FALSE)
	{
		$CI =& get_instance();
		
		// Create an order for today's payment
		$CI->load->model('order_model');
		$customer['customer_id'] = (isset($customer['customer_id'])) ? $customer['customer_id'] : FALSE;
		$order_id = $CI->order_model->CreateNewOrder($client_id, $gateway['gateway_id'], $amount, $credit_card, $subscription_id, $customer['customer_id'], $customer['ip_address']);
		
		// Create the recurring seed
		$response = $this->CreateProfile($client_id, $gateway, $customer, $credit_card, $subscription_id, $amount, $order_id);
		  
		// Process today's payment
		if (date('Y-m-d', strtotime($start_date)) == date('Y-m-d')) {
			$response = $this->ChargeRecurring($client_id, $gateway, $order_id, $response['transaction_tag'], $response['auth_num'], $amount);
		
			if($response['success'] == TRUE){
				$CI->order_model->SetStatus($order_id, 1);
				$response_array = array('charge_id' => $order_id, 'recurring_id' => $subscription_id);
				$response = $CI->response->TransactionResponse(100, $response_array);
			} else {
				// Make the subscription inactive
				$CI->subscription_model->MakeInactive($subscription_id);
				$CI->order_model->SetStatus($order_id, 0);
				
				$response_array = array('reason' => $response['reason']);
				$response = $CI->response->TransactionResponse(2, $response_array);
			}
		} else {
			$response = $CI->response->TransactionResponse(100, array('recurring_id' => $subscription_id));
		}
		
		return $response;
	}
	
	
	function Refund($client_id, $order_id, $gateway, $customer, $params, $credit_card)
	{			
		$CI =& get_instance();
		
		$post_url = $gateway['url_live'];
			
		$trxnProperties = array(
					'ExactID'			=> $gateway['terminal_id'],	
			  		'Password'			=> $gateway['password'],
					'Transaction_Type'  => '04',
				 	'Card_Number' 		=> $credit_card['card_num'],
					'Expiry_Date'		=> $credit_card['exp_month'] . substr($credit_card['exp_year'],-2,2),
					'CVD_Presence_Ind' 	=> (empty($credit_card['cvv'])) ? '9' : '1',
					'Customer_Ref' 		=> $order_id,
					'DollarAmount' 		=> $params['amount']
		  		);
		
		if(isset($credit_card->cvv)) {
			$trxnProperties['VerificationStr1'] = $credit_card['cvv'];
		}  
		
		if(isset($customer['customer_id'])) {
			$trxnProperties['CardHoldersName'] = $customer['first_name'].' '.$customer['last_name'];
		} else {
			$name = explode(' ', $credit_card['card_name']);
			$trxnProperties['CardHoldersName'] = $name[0].' '.$name[1];
			
		}
		  
		$trxnProperties = $this->CompleteArray($trxnProperties); 
		  
		$trxnResult = $this->Process($trxnProperties, $post_url, $order_id);
		
		if($trxnResult->EXact_Resp_Code == '00'){
			$CI->load->model('order_authorization_model');
			$CI->order_authorization_model->SaveAuthorization($order_id, $trxnResult->Transaction_Tag);
			$response_array = array('charge_id' => $order_id);
			$response = $CI->response->TransactionResponse(1, $response_array);
		} else {
			$response_array = array('reason' => $trxnResult->EXact_Message);
			$response = $CI->response->TransactionResponse(2, $response_array);
		}
		
		return $response;

	}
	
	function CreateProfile($client_id, $gateway, $customer, $credit_card, $subscription_id, $amount, $order_id)
	{
		$CI =& get_instance();
		
		$post_url = $gateway['url_live'];

		// Create the recurring seed
		
		$transaction = array(
		'ExactID'			=> $gateway['terminal_id'],	
  		'Password'			=> $gateway['password'],
		'Transaction_Type'  => '00',
	 	'Card_Number' 		=> $credit_card['card_num'],
		'Expiry_Date'		=> $credit_card['exp_month'] . substr($credit_card['exp_year'],-2,2),
		'CVD_Presence_Ind' 	=> (empty($credit_card['cvv'])) ? '9' : '1',
		'Customer_Ref' 		=> $order_id,
		'DollarAmount' 		=> $amount
	    );
		
		if (isset($credit_card['cvv'])) {
			$transaction['VerificationStr1'] = $credit_card['cvv'];
		}  
		
		if (isset($customer['customer_id'])) {
			$transaction['CardHoldersName'] = $customer['first_name'] . ' ' . $customer['last_name'];
		} else {
			$name = explode(' ', $credit_card['card_name']);
			$transaction['CardHoldersName'] = $name[0] . ' ' . $name[1];
		}
		
		if (isset($customer['ip_address']) and !empty($customer['ip_address'])) {
			$transaction['Client_IP'] = $customer['ip_address'];
		}
		
		$transaction = $this->CompleteArray($transaction);
		
		$post_response = $this->Process($transaction, $post_url, $order_id);
		
		if($post_response->EXact_Resp_Code == '00') {
			$response['success'] = TRUE;
			// Save the Auth information
			$CI->load->model('subscription_model');
			$CI->subscription_model->SaveApiCustomerReference($subscription_id, $post_response->Transaction_Tag);
			$CI->subscription_model->SaveApiAuthNumber($subscription_id, $post_response->Authorization_Num);
			$response['transaction_tag'] = $post_response->Transaction_Tag;
			$response['auth_num'] = $post_response->Authorization_Num;
		} else {
			$response['success'] = FALSE;
			$response['reason'] = $post_response->EXact_Message;
		}
		
		return $response;
	}
	
	function AutoRecurringCharge ($client_id, $order_id, $gateway, $params) {
		return $this->ChargeRecurring($client_id, $gateway, $order_id, $params['api_customer_reference'], $params['api_payment_reference'], $params['amount']);
	}
	
	function ChargeRecurring($client_id, $gateway, $order_id, $transaction_tag, $auth_num, $amount)
	{
		$CI =& get_instance();
		
		$post_url = $gateway['url_live'];

		// Create the charge
		
		$trxnProperties = array(
		'ExactID'			=> $gateway['terminal_id'],	
  		'Password'			=> $gateway['password'],
		'Transaction_Type'  => '30',
	 	'Transaction_Tag'	=> $transaction_tag,
		'Authorization_Num'	=> $auth_num,
		'Customer_Ref' 		=> $order_id,
		'DollarAmount' 		=> $amount
        );
		
		$trxnProperties = $this->CompleteArray($trxnProperties); 
		
		$post_response = $this->Process($trxnProperties, $post_url, $order_id);
		
		if($post_response->EXact_Resp_Code == '00') {
			$response['success'] = TRUE;
			// Save the Auth information
			$CI->load->model('order_authorization_model');
			$CI->order_authorization_model->SaveAuthorization($order_id, $post_response->Authorization_Num);
		} else {
			$response['success'] = FALSE;
			$response['reason'] = $post_response->EXact_Message;
		}
		
		return $response;
	}
	
	function CancelRecurring($client_id, $subscription)
	{	
		return TRUE;
	}
	
	function UpdateRecurring()
	{
		return TRUE;
	}
	
	function Process($trxnProperties, $post_url, $order_id) 
	{
		$trxn = array("Transaction"=>$trxnProperties);
		
		$client = new SoapClient($post_url);
		$trxnResult = $client->__soapCall('SendAndCommit', $trxn);
		
		return $trxnResult;
	}
	
	function CompleteArray($array = array())
	{
		$complete_if_blank = array(
								"Ecommerce_Flag",
								"XID",
								"ExactID",
								"CAVV",
								"Password",
								"CAVV_Algorithm",
								"Transaction_Type",
								"Reference_No",
								"Customer_Ref",
								"Reference_3",
								"Client_IP",		
								"Client_Email",		
								"Language",	
								"Card_Number",
								"Expiry_Date",
								"CardHoldersName",
								"Track1",
								"Track2",
								"Authorization_Num",
								"Transaction_Tag",
								"DollarAmount",
								"VerificationStr1",
								"VerificationStr2",
								"CVD_Presence_Ind",
								"Secure_AuthRequired",
								"Secure_AuthResult",
								
								// Level 2 fields 
								"ZipCode",
								"Tax1Amount",
								"Tax1Number",
								"Tax2Amount",
								"Tax2Number",
								
								"SurchargeAmount",	//Used for debit transactions only
								"PAN"
							);
							
		while (list(,$v) = each($complete_if_blank)) {
			if (!key_exists($v, $array)) {
				$array[$v] = '';
			}
		}
		
		return $array;
	}
}