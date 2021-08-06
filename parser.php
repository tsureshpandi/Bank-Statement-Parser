<?php

function parse_bank_statement($Filepath="")
{
	// Excel reader from http://code.google.com/p/php-excel-reader/

	require_once('spreadsheet-reader/php-excel-reader/excel_reader2.php');
	require_once('spreadsheet-reader/SpreadsheetReader.php');

	date_default_timezone_set('UTC');

	try
	{
		$Extension = strtolower(pathinfo($Filepath, PATHINFO_EXTENSION));

		// some files have extension with xls & file content is tab, comma separated, so we have to parse that too.
		if ($Extension != "csv" && $Extension != "tsv" && mime_content_type($Filepath) == "text/plain")
		{
			$delim = ob_get_delimiter($Filepath);

			if ($delim == "\t")
			{
				copy($Filepath, $Filepath . ".tsv");
				$Filepath = $Filepath . ".tsv";
				$Extension = "tsv";
			}
			else if ($delim == ",")
			{
				copy($Filepath, $Filepath . ".csv");
				$Filepath = $Filepath . ".csv";
				$Extension = "csv";
			}
		}

		// $Spreadsheet = new SpreadsheetReader($Filepath, false, true);
		
		$Spreadsheet = new SpreadsheetReader($Filepath, false, false, array(
			"BuiltinFormats" => array(
				22 => 'd/m/yy h:mm'
			)
		));
		// $Spreadsheet->ChangeSheet(0);

		$isDataDetected = false;
		// If Data detected, then grap the needed columns indexes.
		$indexes = array();
		$indexes2 = array();
		$parsedData = array();
		// Empty Row after header detect.

		// /^(tt|as)/i
		$patterns = array(
			"date"	=> "/^(" . implode( "|", array( "Tran Date", "Transaction Date", "TxnPostedDate", "Txn Date", "Txn. date", "Posted Date", "Tr Date", "Value Date", "Date" )) . ")/i",
			// "btrMode"	=> "/^(" . implode( "|", array( "mode", "cod")) .")/i",
			"desc"	=> "/^(" . implode( "|", array( "Description", "Transaction Description", "Narration", "Transaction Remark", "Remark", "Remarks", "Particular", "Particulars", "Transaction Particulars" )) .")/i",
			"chqno"		=> "/^(" . implode( "|", array( "ChequeNo", "Cheque No", "ChqNo", "RefNo", "Ref No", "Cheque", "Chq", "Ref" )) . ")/i",
			"debit"		=> "/^(" . implode( "|", array( "DR", "Debit", "Withdraw", "DRINR", "debitinr", "withdrawinr", "Dt" )). ")/i",
			"credit"	=> "/^(" . implode( "|", array( "CR","Credit","Deposit","crinr","creditinr","depositinr","ct" )). ")/i",
			"type"	=> "/^(" . implode( "|", array( "DebitCredit", "CreditDebit", "CRDR", "CR\/DR", "DRCR", "DC", "CD" )).")/i",
			"amount"	=> "/^(" . implode( "|", array( "Amount", "Transaction Amount", "TxnAmount", "TrAmount", "Amt", "amountinr", "transactionamountinr", "txnamountinr", "tramountinr", "amtinr" )).")/i",
			"balance"	=> "/^(" . implode( "|", array( "balance", "bal" )). ")/i",
		);

		foreach ($Spreadsheet as $Row)
		{
			if ($Extension == "csv" && (!is_array($Row) || count($Row) == 1))
			{
				$Row = str_getcsv($Row[0]);
			}

			if ($isDataDetected)
			{
				$rowData = array();
				$emptyDetect = 0;

				/*
				if (empty($Row) || empty($Row[$indexes["date"]]))
				{
					continue;
				}
				*/

				foreach($indexes as $key=>$val)
				{
					$index = $val[0];

					if (empty($Row[$index]))
					{
						$emptyDetect++;
					}
					else
					{
						$Row[$index] = trim($Row[$index]);

						if (!$Row[$index])
						{
							continue;
						}

						switch($key)
						{
							case "date":
								$date = preg_replace("/(\d+)[\/|-|,](\d+)[\/|-|,](\d+)/i", "$2/$1/$3", $Row[$index]);

								if (is_numeric($date))
								{
									// $date = date("m/d/Y", strtotime("01/01/1900 + " . ($date-2) . " days"));

									$date = gmdate("m/d/Y", ($date - 25569) * 86400);
								}

								$date = date_parse($date);

								if (!$date["year"])
								{
									break;
								}

								$date = $date["year"] . "-";
								$date .= str_pad($date["month"], 2, "0", STR_PAD_LEFT) . "-";
								$date .= str_pad($date["day"], 2, "0", STR_PAD_LEFT);	// . " ";
/*
								$date .= str_pad($date["hour"], 2, "0", STR_PAD_LEFT) . ":";
								$date .= str_pad($date["minute"], 2, "0", STR_PAD_LEFT) . ":";
								$date .= str_pad($date["second"], 2, "0", STR_PAD_LEFT);
*/

								$Row[$index] = $date;
							break;
							case "chqno":
								$Row[$index] = preg_replace("!\s+!", " ", preg_replace("/[^A-Za-z0-9 ]/", "", $Row[$index]));
							break;
							case "debit":
							case "credit":
								$Row[$index] = str_replace(",", "", $Row[$index]);
							break;
							case "amount":
							case "balance":
								$Row[$index] = (double)str_replace(",", "", $Row[$index]);
							break;
							case "desc":
								if (count($val) > 1)
								{
									$Row[$index] = implode(" ", array_filter(array_intersect_key($Row, array_flip($val))));
								}
							break;
						}

						$rowData[$key] = $Row[$index];
					}
				}

				// Skip Empty Rows
				if ($emptyDetect && count($indexes) == $emptyDetect)
				{
					continue;
				}

				if (
					!empty($rowData["type"]) && !empty($rowData["amount"]) &&
					empty($rowData["debit"]) && empty($rowData["credit"])
				) {
					if (preg_match($patterns["debit"], $rowData["type"]))
					{
						$rowData["debit"] = $rowData["amount"];
					}
					else if (preg_match($patterns["credit"], $rowData["type"]))
					{
						$rowData["credit"] = $rowData["amount"];
					}
				}

				if (
					empty($rowData) ||
					empty($rowData["date"]) ||
					empty($rowData["desc"]) ||
					(empty($rowData["debit"]) && empty($rowData["credit"]))
				) {
					continue;
				}

				$parsedData[] = $rowData;
				continue;
			}

			$identified = 0;
			$Row = array_map("trim", $Row);

			foreach($patterns as $key => $pattern)
			{
				$output = preg_grep($pattern, $Row);

				if (empty($output))
				{
					continue;
				}

				$keys = array_keys($output);
				// $indexes[$key] = $keys[0];
				$indexes[$key] = $keys;
				$identified++;
			}

			if ($identified >= 4 && !empty($indexes["type"]) && !empty($indexes["amount"]))
			{
				unset($indexes["debit"]);
				unset($indexes["credit"]);

				$identified = count($indexes);
			}

			if ($identified >= 4)
			{
				$isDataDetected = true;
				// break;
			}
		}

		if (!empty($parsedData))
		{
			foreach($parsedData as $key=>$Row)
			{
				/*
				if (!empty($Row["desc"]))
				{
					preg_match("/(imps|neft|nft|rtgs)/i", $Row["desc"], $matches);
					if (!empty($matches) && !empty($matches[1]))
					{
						$Row["paymentMode"] = $matches[1];
					}
					else
					{
						$Row["paymentMode"] = "";
					}

					// preg_match("/(atm|cash wdl|)/i", $Row["desc"], $matches);
					// Cash Contra Entry.

					$parsedData[$key] = $Row;
				}
				*/

				if (!empty($Row["amount"]))
				{
					$firstChar = "";
					if (!empty($Row["debit"]))
					{
						$Row["debit"] = trim($Row["debit"]);
						$firstChar = strtoupper(substr($Row["debit"], 0, 1));
					}
					else if (!empty($Row["credit"]))
					{
						$Row["credit"] = trim($Row["credit"]);
						$firstChar = strtoupper(substr($Row["credit"], 0, 1));
					}

					if ($firstChar == "D")
					{
						$Row["debit"] = $Row["amount"];
						$Row["credit"] = "";
					}
					else if ($firstChar == "C")
					{
						$Row["credit"] = $Row["amount"];
						$Row["debit"] = "";
					}

					$parsedData[$key] = $Row;
				}
			}
		}

		if (!$isDataDetected)
		{
			throw new Exception("COLUMN_NOT_FOUND");
			return;
		}

		return $parsedData;

	/*
		$Sheets = $Spreadsheet->Sheets();
		foreach ($Sheets as $Index => $Name)s
		{
			$Spreadsheet->ChangeSheet($Index);
			foreach ($Spreadsheet as $Key => $Row)
			{
				print_r($Row);
			}
		}
	*/
	}
	catch (Exception $E)
	{
		throw $E;
		// echo $E->getMessage();
		return false;
	}
}

function ob_get_delimiter($file="")
{
	// The delimiters array to look through
	$delimiters = array(
		"semicolon"	=> ";",
		"tab"		=> "\t",
		"comma"		=> ",",
		"pipe"		=> "|"
	);

	// Load the csv file into a string
	$csv = file_get_contents($file);
	foreach ($delimiters as $key => $delim)
	{
		$res[$key] = substr_count($csv, $delim);
	}

	// reverse sort the values, so the [0] element has the most occured delimiter
	arsort($res);
	reset($res);
	$first_key = key($res);

	return $delimiters[$first_key];
}

