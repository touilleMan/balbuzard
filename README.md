balbuzard
=========

Dead simple multi-client php server


### I - The problem ###

Php standalone server (i.e. `php -S address:port`) doesn't accept multi-client connexion, this leads to deadlocks in case of recursive requests.

```
Ugly problems often require ugly solutions.
 Solving an ugly problem in a pure manner is bloody hard.
```
Rasmus Lerdorf


### II - The solution ###

Balbuzard acts as a proxy between your reqested server address/port and a pool of php standalone servers.


### III - License ###

WTFPLv2, basically do whatever you want with this.
