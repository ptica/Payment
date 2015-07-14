<?php
class CSignature{
	var $privatni, $heslo, $verejny;
	// parametry: jmeno souboru soukromeho klice, heslo k soukromemu klici, jmeno souboru s verejnym klicem
	//function CSignature($privatni="test_key.pem", $heslo="changeit", $verejny="test_cert.pem"){
	function CSignature($privatni, $heslo, $verejny) {
		$fp = fopen($privatni, "r");
		$this->privatni = fread($fp, filesize($privatni));
		fclose($fp);
		$this->heslo=$heslo;

		$fp = fopen($verejny, "r");
		$this->verejny = fread($fp, filesize($verejny));
		fclose($fp);
	}
	
	function sign($text) {
		$pkeyid = openssl_get_privatekey($this->privatni, $this->heslo);
		openssl_sign($text, $signature, $pkeyid);
		$signature = base64_encode($signature);
		openssl_free_key($pkeyid);
		return $signature;
	}
	
	function verify($text, $signature) {
		$pubkeyid = openssl_get_publickey($this->verejny);
		$signature = base64_decode($signature);
		$vysledek = openssl_verify($text, $signature, $pubkeyid);
		openssl_free_key($pubkeyid);
		return (($vysledek==1) ? true : false);
	}
}
