tls:
  stores:
    default:
      defaultCertificate:
        certFile: /certs/local-dev.pem
        keyFile: /certs/local-dev-key.pem
  certificates:
    - certFile: /certs/local-dev.pem
      keyFile: /certs/local-dev-key.pem
      stores:
        - default
