#!/usr/bin/env python3
"""
Impide que un workflow que GitHub no pueda compilar llegue a `main`.

## Por qué existe

Entre el 21/08 y el 02/09/2026 el despliegue automático de Converza no corrió ni
una vez, y nadie se enteró. La causa fue una expresión de GitHub Actions **vacía**
dentro de un comentario del bloque `run:` de `deploy.yml`. GitHub sustituye las
expresiones en todo el bloque antes de que exista un shell, así que un `#` no
protege nada: la expresión vacía no compila y el workflow falla al **arrancar**.

Un *startup failure* es peor que un deploy fallido: no se agenda ningún job, no
hay log que abrir y no llega ningún aviso. Nada dentro del workflow puede avisar,
porque el workflow no llega a existir. La única defensa posible es de este lado:
revisar el archivo **antes** de que se mergee. Ver CON-70.

## Qué revisa

1. Que cada workflow sea YAML válido.
2. Que no haya expresiones de Actions vacías — la trampa exacta de CON-70.
3. Que no quede ninguna expresión sin cerrar.

No es un validador completo del lenguaje de expresiones: `actionlint` lo es, y es
el camino natural si esto se queda corto. Esto cubre la clase de error que ya nos
costó doce días, sin traer dependencias nuevas.

## Cuidado al tocar este archivo

Vive aparte **a propósito**: contiene la secuencia que abre una expresión de
Actions, así que si su contenido se pegara dentro de un bloque `run:` de un
workflow, GitHub intentaría sustituirla y romperíamos justo lo que venimos a
evitar. El workflow lo invoca como script; nunca lo incrusta.
"""

import pathlib
import re
import sys

import yaml

# La consola de Windows arranca en cp1252 y revienta con cualquier carácter que no
# entre ahí. Este script se corre también en local, y un validador que se cae al
# imprimir su propio informe es peor que no tenerlo: parecería un fallo del
# workflow revisado. Se fuerza UTF-8 y se degrada en vez de fallar.
for flujo in (sys.stdout, sys.stderr):
    try:
        flujo.reconfigure(encoding="utf-8", errors="replace")
    except (AttributeError, ValueError):  # ya redirigido a algo que no lo soporta
        pass

WORKFLOW_DIR = pathlib.Path(".github/workflows")

# Una expresión completa, con su contenido. DOTALL porque nada impide que alguien
# la parta en dos líneas.
EXPRESION = re.compile(r"\$\{\{(.*?)\}\}", re.DOTALL)
APERTURA = re.compile(r"\$\{\{")


def linea_de(texto: str, posicion: int) -> int:
    return texto.count("\n", 0, posicion) + 1


def revisar(ruta: pathlib.Path) -> list[str]:
    problemas: list[str] = []
    crudo = ruta.read_text(encoding="utf-8")

    try:
        yaml.safe_load(crudo)
    except yaml.YAMLError as e:
        problemas.append(f"{ruta}: no es YAML válido — {str(e).splitlines()[0]}")

    for m in EXPRESION.finditer(crudo):
        if m.group(1).strip() == "":
            problemas.append(
                f"{ruta}:{linea_de(crudo, m.start())}: expresión de Actions VACÍA. "
                "No compila, y el workflow entero no arranca — ni siquiera dentro "
                "de un comentario. Describila con palabras."
            )

    abiertas = len(APERTURA.findall(crudo))
    cerradas = len(EXPRESION.findall(crudo))
    if abiertas > cerradas:
        problemas.append(
            f"{ruta}: hay {abiertas - cerradas} expresión(es) de Actions sin cerrar."
        )

    return problemas


def main() -> int:
    if not WORKFLOW_DIR.is_dir():
        print(f"No existe {WORKFLOW_DIR}: nada que revisar.")
        return 0

    archivos = sorted(
        p for p in WORKFLOW_DIR.iterdir() if p.suffix in {".yml", ".yaml"}
    )
    if not archivos:
        print(f"No hay workflows en {WORKFLOW_DIR}.")
        return 0

    problemas: list[str] = []
    for ruta in archivos:
        problemas.extend(revisar(ruta))

    if problemas:
        print("Workflows con problemas:\n")
        for p in problemas:
            print(f"  - {p}")
        print(
            "\nUn workflow que no compila NO genera ningún job, ningún log y ningún "
            "aviso: main se quedaría sin despliegue en silencio. Por eso esto "
            "bloquea el PR."
        )
        return 1

    print(f"OK: {len(archivos)} workflow(s) revisado(s), sin problemas.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
