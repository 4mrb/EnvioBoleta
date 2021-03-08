<?php

/**
 * OpenEnvia
 * Copyright (C) SASCO SpA (https://sasco.cl)
 *
 * Este programa es software libre: usted puede redistribuirlo y/o
 * modificarlo bajo los términos de la Licencia Pública General Affero de GNU
 * publicada por la Fundación para el Software Libre, ya sea la versión
 * 3 de la Licencia, o (a su elección) cualquier versión posterior de la
 * misma.
 *
 * Este programa se distribuye con la esperanza de que sea útil, pero
 * SIN GARANTÍA ALGUNA; ni siquiera la garantía implícita
 * MERCANTIL o de APTITUD PARA UN PROPÓSITO DETERMINADO.
 * Consulte los detalles de la Licencia Pública General Affero de GNU para
 * obtener una información más detallada.
 *
 * Debería haber recibido una copia de la Licencia Pública General Affero de GNU
 * junto a este programa.
 * En caso contrario, consulte <http://www.gnu.org/licenses/agpl.html>.
 */

namespace website\Dte;

use GuzzleHttp\Psr7\Response;
use \sasco\OpenEnvia\Estado;

/**
 * Clase que permite interacturar con el envío de boletas al SII mediante
 * las funcionalidades extras de OpenEnvia.
 * Se provee como una clase aparte, porque es una funcionalidad que por defecto
 * viene desactivada.
 *
 */
class Utility_EnvioBoleta
{
    private static $retry = 10; ///< Veces que se reintentará conectar a SII al usar el servicio web
    private static $verificar_ssl = true; ///< Indica si se deberá verificar o no el certificado SSL del SII        
    private static $PRODUCCION_1="https://api.sii.cl/recursos/v1";
    private static $PRODUCCION_2="https://rahue.sii.cl/recursos/v1";
    private static $CERTIFICACION_1="https://apicert.sii.cl/recursos/v1";
    private static $CERTIFICACION_2="https://pangal.sii.cl/recursos/v1";
    protected static $URL;
    protected static $URLEnvio;

    private static $revision_estados = [
        'rechazados' => ['RSC', 'RCT', 'RCH', 'RFR'],
        'no_final' => ['002', '004', '005', '007', '106', '107', '-11', '-8', 'VOF', 'PRD', 'SOK', 'CRT', 'REC'],
    ]; ///< Posibles estados de revisión de envío al SII de los DTE

    private static $rechazado=[
        'RSC'=>'Rechazado por Schema',
        'RCO'=>'Rechazado por consistencia',
        'RCH'=>'Rechazado por errores en informacion',
        'RFR' => 'Rechazado por error en firma',
        'RCT' => 'Rechazado por Error en Caratula'

    ];

    private static function setserver(){
        $certificacion = \sasco\OpenEnvia\Sii::getAmbiente();
        if($certificacion==0){
            self::$URL=self::$PRODUCCION_1;
            self::$URLEnvio=self::$PRODUCCION_2;
        }else{       
            self::$URL=self::$CERTIFICACION_1;
            self::$URLEnvio=self::$CERTIFICACION_2;
        }
    }
    /**
     * Método que envía un XML de EnvioBoleta al SII y entrega el Track ID del envío
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @author IdFour.cl
     * @version 2020-11-19
     */
    public static function enviar($usuario, $empresa, $xml, $Firma, $gzip = false, $retry = null)
    {
        self::setserver();
        ///OBTENCION DEL TOKEN
        $token=self::getToken($Firma);
        ////ENVIO DEL DOCUMENTO
        $respuesta=self::enviarNew($usuario, $empresa, $xml, $token, $gzip, $retry);        
        return $respuesta;
    }

    /**
     * Método que entrega el estado normalizado del envío e la boleta al SII
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @author IdFour.cl
     * @version 2020-11-19
     */
    public static function estado_normalizado($rut, $dv, $track_id, $Firma, $dte, $folio)
    {
        self::setserver();
        //La consulta del estado aun se encuentra en desarrollo.
        $empresa=$rut."-".$dv;
        ///OBTENCION DEL TOKEN
        $token=self::getToken($Firma);
        $resp=self::consultaBoleta($rut, $dv, $track_id, $Firma, $dte, $folio,$token);
        $r=array();
        $r['estado']=$resp['codigo'];
        $r['detalle']=$resp['descripcion'];
        return $r;
    }

     /**
     * Método para solicitar la semilla para la autenticación automática.
     * Nota: la semilla tiene una validez de 2 minutos.
     *
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @author IdFour.cl
     * @version 2020-11-19
     */
    private static function getSeed()
    {                
        $ch = curl_init(self::$URL."/boleta.electronica.semilla");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept:application/xml') );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp=curl_exec($ch);
        $respon=new \SimpleXMLElement($resp, LIBXML_COMPACT);
        if ($resp===false or (string)$respon->xpath('/SII:RESPUESTA/SII:RESP_HDR/ESTADO')[0]!=='00') {
            \sasco\OpenEnvia\Log::write(
                \sasco\OpenEnvia\Estado::AUTH_ERROR_SEMILLA,
                \sasco\OpenEnvia\Estado::get(\sasco\OpenEnvia\Estado::AUTH_ERROR_SEMILLA)
            );
            return false;
        }
                
        return (string)$respon->xpath('/SII:RESPUESTA/SII:RESP_BODY/SEMILLA')[0];
    }

/**
     * Método que firma una semilla previamente obtenida
     * @param seed Semilla obtenida desde SII
     * @param Firma objeto de la Firma electrónica o arreglo con configuración de la misma
     * @return Solicitud de token con la semilla incorporada y firmada
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @author IdFour.cl
     * @version 2020-11-19
     */
    private static function getTokenRequest($seed, $Firma = [])
    {   
         if (is_array($Firma))
            $Firma = new \sasco\OpenEnvia\FirmaElectronica($Firma);
        $seedSigned = $Firma->signXML(
            (new \sasco\OpenEnvia\XML())->generate([
                'getToken' => [
                    'item' => [
                        'Semilla' => $seed
                    ]
                ]
            ])->saveXML()
        );
        if (!$seedSigned) {
            \sasco\OpenEnvia\Log::write(
                \sasco\OpenEnvia\Estado::AUTH_ERROR_FIRMA_SOLICITUD_TOKEN,
                \sasco\OpenEnvia\Estado::get(\sasco\OpenEnvia\Estado::AUTH_ERROR_FIRMA_SOLICITUD_TOKEN)
            );
            return false;
        }
        return $seedSigned;
    }

     /**
     * Método para obtener el token de la sesión a través de una semilla
     * previamente firmada
     *
     * WSDL producción: https://palena.sii.cl/DTEWS/GetTokenFromSeed.jws?WSDL
     * WSDL certificación: https://maullin.sii.cl/DTEWS/GetTokenFromSeed.jws?WSDL
     *
     * @param Firma objeto de la Firma electrónica o arreglo con configuración de la misma
     * @return Token para autenticación en SII o =false si no se pudo obtener
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @author IdFour.cl
     * @version 2020-11-19
     */
    public static function getToken($Firma = [])
    {  
        if (!$Firma) return false;
        $semilla = self::getSeed();
        if (!$semilla) return false;
        $requestFirmado = self::getTokenRequest($semilla, $Firma);
        if (!$requestFirmado) return false;
       $header = [
        'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; OpenEnvia)',
        'Referer: https://libredte.cl',
        'Content-type: application/xml'
        ];
        $ch = curl_init(self::$URL."/boleta.electronica.token");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER,  $header);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestFirmado);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp=curl_exec($ch);
        $respon=new \SimpleXMLElement($resp, LIBXML_COMPACT);
        if ($resp===false or (string)$respon->xpath('/SII:RESPUESTA/SII:RESP_HDR/ESTADO')[0]!=='00') {
            \sasco\OpenEnvia\Log::write(
                \sasco\OpenEnvia\Estado::AUTH_ERROR_TOKEN,
                \sasco\OpenEnvia\Estado::get(\sasco\OpenEnvia\Estado::AUTH_ERROR_TOKEN)
            );
           
            return false;
        }
       return (string)$respon->xpath('/SII:RESPUESTA/SII:RESP_BODY/TOKEN')[0];
    }

    
    /**
     * Método que realiza el envío de un DTE al SII
     * Referencia: http://www.sii.cl/factura_electronica/factura_mercado/envio.pdf
     * @param usuario RUN del usuario que envía el DTE
     * @param empresa RUT de la empresa emisora del DTE
     * @param dte Documento XML con el DTE que se desea enviar a SII
     * @param token Token de autenticación automática ante el SII
     * @param gzip Permite enviar el archivo XML comprimido al servidor
     * @param retry Intentos que se realizarán como máximo para obtener respuesta
     * @return Respuesta XML desde SII o bien null si no se pudo obtener respuesta
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @author IdFour.cl
     * @version 2020-11-19
     */
    public static function enviarNew($usuario, $empresa, $dte, $token, $gzip = false, $retry = null)
    {
        // definir datos que se usarán en el envío
        list($rutSender, $dvSender) = explode('-', str_replace('.', '', $usuario));
        list($rutCompany, $dvCompany) = explode('-', str_replace('.', '', $empresa));
        if (strpos($dte, '<?xml')===false) {
            $dte = '<?xml version="1.0" encoding="ISO-8859-1"?>'."\n".$dte;
        }
        do {
            $file = sys_get_temp_dir().'/dte_'.md5(microtime().$token.$dte).'.'.($gzip?'gz':'xml');
        } while (file_exists($file));
        if ($gzip) {
            $dte = gzencode($dte);
            if ($dte===false) {
                \sasco\OpenEnvia\Log::write(Estado::ENVIO_ERROR_GZIP, Estado::get(Estado::ENVIO_ERROR_GZIP));
                return false;
            }
        }
        file_put_contents($file, $dte);
        $data = [
            'rutSender' => $rutSender,
            'dvSender' => $dvSender,
            'rutCompany' => $rutCompany,
            'dvCompany' => $dvCompany,
            'archivo' => curl_file_create(
                $file,
                $gzip ? 'application/gzip' : 'application/xml',
                basename($file)
            ),
        ];
        // definir reintentos si no se pasaron
        if (!$retry) {
            $retry = self::$retry;
        }
        // crear sesión curl con sus opciones
        $curl = curl_init();
        $header = [
            'User-Agent: Mozilla/4.0 (compatible; PROG 1.0; OpenEnvia)',
            'Referer: https://libredte.cl',
            'Cookie: TOKEN='.$token,
        ];
        //$url='https://pangal.sii.cl/recursos/v1/boleta.electronica.envio';
        $url=self::$URLEnvio.'/boleta.electronica.envio';
        //throw new \Exception($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // si no se debe verificar el SSL se asigna opción a curl, además si
        // se está en el ambiente de producción y no se verifica SSL se
        // generará una entrada en el log
        /*
        if (!self::$verificar_ssl) {
            if (self::getAmbiente()==self::PRODUCCION) {
                $msg = Estado::get(Estado::ENVIO_SSL_SIN_VERIFICAR);
                \sasco\OpenEnvia\Log::write(Estado::ENVIO_SSL_SIN_VERIFICAR, $msg, LOG_WARNING);
            }
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        }
        */
        // enviar XML al SII
        for ($i=0; $i<$retry; $i++) {
            $response = curl_exec($curl);
            if ($response and $response!='Error 500') {
                break;
            }
        }
        unlink($file);
        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response=='Error 500') {
            if (!$response) {
                \sasco\OpenEnvia\Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response=='Error 500') {
                \sasco\OpenEnvia\Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            return false;
        }
        // cerrar sesión curl
        curl_close($curl);

        $r=json_decode($response, true);

        //$res=self::status_sended($rutSender,$dvSender,$r['track_id'],$r,$token,1);

        return $r;
    }

    public static function consultaBoleta($rut, $dv, $track_id, $Firma, $dte, $folio,$token,$retry=1)
    {
        $curl = curl_init();
        $header = [
            'User-Agent: Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)',
            'Referer: https://utus.cl',
            'Cookie: TOKEN='.$token,
            'accept: application/json',
        ];

        
        //throw new \Exception('  '.'$token '.$token);
        $Emisor = new \website\Dte\Model_Contribuyente($rut);
        $DteEmitido = new Model_DteEmitido($Emisor->rut, $dte, $folio, $Emisor->enCertificacion());
        $date=date_create($DteEmitido->fecha);
        $fecha_envio=date_format($date, 'd-m-Y');
        $receptor_rut=explode("-",$DteEmitido->getReceptor()->getRUT());
        $rut_receptor=str_replace('.','',$receptor_rut[0]);
        $dv_receptor=$receptor_rut[1];
        $url = self::$URL.'/boleta.electronica/'.$rut.'-'.$dv.'-'.$dte.'-'.$folio.'/estado?rut_receptor='.$rut_receptor.'&dv_receptor='.$dv_receptor.'&monto='.$DteEmitido->getTotal().'&fechaEmision='.$fecha_envio;
       // $url = self::$URL.'/boleta.electronica.envio/'.$rut.'-'.$dv.'-'.$track_id;
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // enviar al SII
        for ($i=0; $i<$retry; $i++) {
            $response = curl_exec($curl);
            if ($response and $response!='Error 500') {
                break;
            }
        }
        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response=='Error 500') {
            if (!$response) {
                \sasco\OpenEnvia\Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response=='Error 500') {
                \sasco\OpenEnvia\Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            return false;
        }
        // cerrar sesión curl
        curl_close($curl);
        //throw new \Exception('  '.'$token '.$token.' URL '. $url);

        //echo $response;
        //throw new \Exception($response);
        //throw new \Exception(self::status_sended($rut, $dv,$track_id,$response,$token,$retry));
        $respuesta=self::status_sended($rut, $dv,$track_id,$response,$token,$retry);
        
        
        //if(in_array($respuesta['estado'],self::$rechazado)){
        if(isset(self::$rechazado[$respuesta['estado']])){
           // throw new \Exception('dentro de la condición '. $respuesta['estado']);
            $resp['codigo']=$respuesta['estado'];
            $resp['descripcion']=self::$rechazado[$respuesta['estado']];
            return $resp;
        }
    
        return json_decode($response, true);
    }


    public static function status_sended($rut,$dv,$track_id,$r,$token,$retry=1){
        
        $curl = curl_init();
        $header = [
            'User-Agent: Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)',
            'Referer: https://utus.cl',
            'Cookie: TOKEN='.$token,
            'accept: application/json',
        ];

        $url = self::$URL.'/boleta.electronica.envio/'.$rut.'-'.$dv.'-'.$track_id;

        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // enviar al SII
        for ($i=0; $i<$retry; $i++) {
            $response = curl_exec($curl);
            if ($response and $response!='Error 500') {
                break;
            }
        }
        // verificar respuesta del envío y entregar error en caso que haya uno
        if (!$response or $response=='Error 500') {
            if (!$response) {
                \sasco\OpenEnvia\Log::write(Estado::ENVIO_ERROR_CURL, Estado::get(Estado::ENVIO_ERROR_CURL, curl_error($curl)));
            }
            if ($response=='Error 500') {
                \sasco\OpenEnvia\Log::write(Estado::ENVIO_ERROR_500, Estado::get(Estado::ENVIO_ERROR_500));
            }
            return false;
        }
        // cerrar sesión curl
        curl_close($curl);
        $respuesta=json_decode($response, true);
        $res['estado']=$respuesta['estado'];
        $res['track_id']=$respuesta['trackid'];
        $res['fecha_recepcion']=$respuesta['fecha_recepcion'];
        
        //throw new \Exception('respuesta: '.$res['track_id'].'-'.$res['estado']);

        return $res;
    }

}   