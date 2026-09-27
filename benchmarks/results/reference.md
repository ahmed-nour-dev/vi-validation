# vi/validation benchmark results

PHP 8.4.19 · illuminate/validation v11.47.0 · Linux 6.18.44-fc-v37 · Intel(R) Xeon(R) Processor @ 2.10GHz (4 CPUs) · OPcache off · JIT off · 2026-09-27T15:12:54+00:00

## One-time costs (per schema)

| Scenario | Compile schema | Native codegen + persist | Artifact load | Native? |
| :--- | ---: | ---: | ---: | :---: |
| user_import | 0.162 ms | 1.929 ms | 0.311 ms | yes |
| order_lines | 0.043 ms | — | — | no |
| api_payload | 0.044 ms | — | — | no |

## Steady-state throughput

| Scenario | Path | Rows | Time | Rows/s | vs Laravel | Peak memory added |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: |
| user_import | laravel | 1,000 | 0.307 s | 3,253 | — | 65.3 KB |
| user_import | factory | 1,000 | 0.015 s | 66,345 | 20.4× | 6.8 KB |
| user_import | engine | 1,000 | 0.007 s | 135,671 | 41.7× | 5.2 KB |
| user_import | native | 1,000 | 0.004 s | 257,440 | 79.1× | 5.5 KB |
| user_import | laravel | 10,000 | 3.016 s | 3,316 | — | 46.5 KB |
| user_import | factory | 10,000 | 0.130 s | 77,000 | 23.2× | 6.8 KB |
| user_import | engine | 10,000 | 0.070 s | 143,374 | 43.2× | 5.2 KB |
| user_import | native | 10,000 | 0.032 s | 308,428 | 93× | 5.5 KB |
| user_import | factory | 100,000 | 1.385 s | 72,177 | 21.8× | 6.8 KB |
| user_import | engine | 100,000 | 0.721 s | 138,768 | 41.8× | 5.2 KB |
| user_import | native | 100,000 | 0.321 s | 311,833 | 94× | 5.5 KB |
| order_lines | laravel | 1,000 | 0.228 s | 4,395 | — | 262.9 KB |
| order_lines | factory | 1,000 | 0.013 s | 75,563 | 17.2× | 7.0 KB |
| order_lines | engine | 1,000 | 0.007 s | 137,190 | 31.2× | 5.4 KB |
| order_lines | laravel | 10,000 | 2.394 s | 4,178 | — | 431.8 KB |
| order_lines | factory | 10,000 | 0.136 s | 73,572 | 17.6× | 7.0 KB |
| order_lines | engine | 10,000 | 0.080 s | 125,139 | 30× | 5.4 KB |
| order_lines | factory | 100,000 | 1.369 s | 73,041 | 17.5× | 7.0 KB |
| order_lines | engine | 100,000 | 0.792 s | 126,261 | 30.2× | 5.4 KB |
| api_payload | laravel | 1,000 | 0.283 s | 3,531 | — | 51.5 KB |
| api_payload | factory | 1,000 | 0.015 s | 68,036 | 19.3× | 6.6 KB |
| api_payload | engine | 1,000 | 0.008 s | 125,328 | 35.5× | 5.4 KB |
| api_payload | laravel | 10,000 | 2.838 s | 3,524 | — | 49.5 KB |
| api_payload | factory | 10,000 | 0.153 s | 65,295 | 18.5× | 6.6 KB |
| api_payload | engine | 10,000 | 0.083 s | 120,959 | 34.3× | 5.4 KB |
| api_payload | factory | 100,000 | 1.627 s | 61,465 | 17.4× | 6.6 KB |
| api_payload | engine | 100,000 | 0.847 s | 118,127 | 33.5× | 5.4 KB |
