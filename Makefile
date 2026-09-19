.PHONY: qa cs fix stan test it pg mysql crap db-up db-down

qa:      ; composer qa
cs:      ; composer cs
fix:     ; composer cs:fix
stan:    ; composer stan
test:    ; composer test
it:      ; composer test:integration
pg:      ; composer test:pg
mysql:   ; composer test:mysql
crap:    ; composer crap
db-up:   ; docker compose up -d --wait
db-down: ; docker compose down -v
