<?php

namespace App\Services\Xml;

use Carbon\Carbon;
use SimpleXMLElement;

class CfdiParserService
{
    /**
     * Extract payment amount and date from Complemento de Pago (pago20/pago10).
     * A single complement can contain multiple <Pago> nodes; we sum their Monto.
     *
     * NOTE: If a complement has multiple <Pago> nodes with different amounts,
     * they are summed into a single total. This may not match individual bank
     * movements. A future improvement could create one factura per <Pago> node.
     */
    private function extractPaymentComplement(SimpleXMLElement $xml, array $ns): array
    {
        $totalMonto = 0.0;
        $fechaPago = null;

        // Try pago20 (CFDI 4.0) then pago10 (CFDI 3.3)
        $pagoVersions = [
            'pago20' => 'http://www.sat.gob.mx/Pagos20',
            'pago10' => 'http://www.sat.gob.mx/Pagos',
        ];

        foreach ($pagoVersions as $prefix => $defaultUri) {
            $uri = $ns[$prefix] ?? null;

            if (! $uri) {
                // Namespace not declared in XML, try the default URI with XPath
                $xml->registerXPathNamespace($prefix, $defaultUri);
                $pagos = $xml->xpath("//{$prefix}:Pago");

                if (empty($pagos)) {
                    continue;
                }
            } else {
                $xml->registerXPathNamespace($prefix, $uri);
                $pagos = $xml->xpath("//{$prefix}:Pago");

                if (empty($pagos)) {
                    continue;
                }
            }

            foreach ($pagos as $pago) {
                $totalMonto += (float) $pago['Monto'];

                if (! $fechaPago) {
                    $fechaPago = (string) $pago['FechaPago'];
                }
            }

            break; // Found payments, no need to try older version
        }

        return [
            'monto' => $totalMonto,
            'fecha_pago' => $fechaPago
                ? Carbon::parse($fechaPago)->format('Y-m-d')
                : Carbon::now()->format('Y-m-d'),
        ];
    }

    public function parse(string $content): array
    {
        // Harden against XXE (libxml 2.9+ disables it by default, but explicit is better)
        // LIBXML_NONET prevents network access
        // LIBXML_NOENT is usually for entity substitution, but can be risky if misconfigured.
        // In modern PHP, SimpleXMLElement is relatively safe if we don't enable LIBXML_DTDLOAD or LIBXML_NOENT.
        try {
            $xml = new SimpleXMLElement($content, LIBXML_NONET | LIBXML_NOWARNING);
        } catch (\Exception $e) {
            throw new \Exception('Invalid XML format or security violation: '.$e->getMessage());
        }

        $ns = $xml->getNamespaces(true);

        // Ensure namespaces are registered
        if (! isset($ns['cfdi'])) {
            $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
        } else {
            $xml->registerXPathNamespace('cfdi', $ns['cfdi']);
        }

        if (! isset($ns['tfd'])) {
            $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
        } else {
            $xml->registerXPathNamespace('tfd', $ns['tfd']);
        }

        // Use XPath for safer extraction of attributes in namespaces
        $timbre = $xml->xpath('//tfd:TimbreFiscalDigital');
        $uuid = isset($timbre[0]) ? (string) $timbre[0]['UUID'] : '';

        // Extract Root attributes
        $total = (float) $xml['Total'];
        $fechaRaw = (string) $xml['Fecha'];
        $fecha = Carbon::parse($fechaRaw)->format('Y-m-d');
        $folio = (string) $xml['Folio'];
        $tipoComprobante = (string) $xml['TipoDeComprobante']; // I=Ingreso, E=Egreso, P=Pago, T=Traslado, N=Nómina
        $metodoPago = (string) $xml['MetodoPago']; // PUE or PPD (absent on type P/T/N)

        // For Complementos de Pago (type P), extract payment amount and date
        // from the pago20/pago10 complement instead of the root Total (which is 0).
        if ($tipoComprobante === 'P') {
            $paymentData = $this->extractPaymentComplement($xml, $ns);

            if ($paymentData['monto'] <= 0) {
                throw new \Exception('Complemento de Pago inválido: no se encontró el nodo de pagos (pago20/pago10) o el monto es 0.');
            }

            $total = $paymentData['monto'];
            $fecha = $paymentData['fecha_pago'];
        }

        // Extract Emisor
        $emisor = $xml->xpath('//cfdi:Emisor');
        $rfcEmisor = isset($emisor[0]) ? (string) $emisor[0]['Rfc'] : '';
        $nombreEmisor = isset($emisor[0]) ? (string) $emisor[0]['Nombre'] : '';

        // Extract Receptor
        $receptor = $xml->xpath('//cfdi:Receptor');
        $rfcReceptor = isset($receptor[0]) ? (string) $receptor[0]['Rfc'] : '';
        $nombreReceptor = isset($receptor[0]) ? (string) $receptor[0]['Nombre'] : '';

        return [
            'uuid' => $uuid,
            'folio' => $folio,
            'fecha_emision' => $fecha,
            'total' => $total,
            'tipo_comprobante' => $tipoComprobante ?: null,
            'metodo_pago' => $metodoPago ?: null,
            'rfc_emisor' => $rfcEmisor,
            'nombre_emisor' => $nombreEmisor,
            'rfc_receptor' => $rfcReceptor,
            'nombre_receptor' => $nombreReceptor,
        ];
    }
}
