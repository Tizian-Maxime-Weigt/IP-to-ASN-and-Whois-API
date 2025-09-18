# IP-to-ASN Lookup API (fast hehe)
This API allows you to lookup the **ASN**, **country**, **country name**, and **provider name** for a given IPv4 or IPv6 address. This API uses an very Optimized Cache to have very fast response times. The API does Support Quic (http3) and http2 to deliver fast responses! ⚡

**Auto-detection feature:** If no IP parameter is provided, the API automatically uses the client's IP address from the request.

## Base URL
```
https://cdn.t-w.dev/whois?ip={IP}&locale={locale}
```
* `ip` (optional) – IPv4 or IPv6 address to query. If omitted, uses client IP
* `locale` (optional) – `de`, `en`, or `both` (default: `both`)

***

## Request
### Method
* **GET** or **POST**

### Parameters
| Name   | Type   | Required | Description                                     |
| ------ | ------ | -------- | ----------------------------------------------- |
| ip     | string | No       | IPv4 or IPv6 address to query. If omitted, automatically uses the client's IP address |
| locale | string | No       | Language for `country_name`: `de`, `en`, `both` |

***

## Response
### Success (200)
| Field         | Type   | Description                               |
| ------------- | ------ | ----------------------------------------- |
| ip            | string | The queried IP address (provided or auto-detected) |
| asn           | string | Autonomous System Number                  |
| country       | string | 2-letter country code of the ASN          |
| country_name | string | Localized country name (`locale` applied) |
| description   | string | Cleaned provider name (normalized)        |
| logo          | string | Provider logo (not all)                   |

**Example with specified IP:**
```json
{
  "ip": "8.8.8.8",
  "asn": "15169",
  "country": "US",
  "country_name": "United States / Vereinigte Staaten",
  "description": "Google",
  "logo": "https://cdn.t-w.dev/img/Google.webp"
}
```

**Example using client IP (no ip parameter):**
```bash
curl https://cdn.t-w.dev/whois
```
```json
{
  "ip": "203.0.113.45",
  "asn": "64512",
  "country": "DE", 
  "country_name": "Germany / Deutschland",
  "description": "Example ISP",
  "logo": null
}
```

### Error (400+)
| Field | Type   | Description                |
| ----- | ------ | -------------------------- |
| error | string | Description of the problem |

**Possible errors:**
```json
{ "error": "Invalid IP address" }
```
```json
{ "error": "ASN not found for given IP" }
```

***

## API Flow
```mermaid
flowchart TD
    A[Client Request] --> B{IP parameter provided?}
    B -- No --> F2[Use Client IP from Request]
    B -- Yes --> D{IP valid?}
    D -- No --> E[Return Error: Invalid IP address]
    D -- Yes --> F[Lookup IP in ASN database]
    F2 --> F
    F --> G{ASN found?}
    G -- Yes --> H[Return JSON with ip, asn, country, country_name, description, logo]
    G -- No --> I[Return Error: ASN not found]
```
