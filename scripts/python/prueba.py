import sys
import tabula
import json
import pandas as pd

try:
    pdf_path = 'T:\\CUENTAS PECE\\2633 NOGALES\\NOG del 2 al 19 Junio.pdf'

    # 1. Leemos el PDF. Tabula-py devuelve una lista de DataFrames de pandas.
    #    Añadimos la opción de pandas para que no intente adivinar los encabezados.
    lista_de_dataframes = tabula.read_pdf(
        pdf_path,
        pages='all',
        lattice=True, # Usamos tu descubrimiento, es mejor para tablas con líneas.
        pandas_options={'header': None}
    )
    
    # 2. Verificamos si se extrajo alguna tabla.
    if not lista_de_dataframes:
        print(json.dumps([])) # Devolvemos un JSON de array vacío.
        sys.exit(0)
    
    # 3. Unimos las tablas de todas las páginas en un único DataFrame.
    df_completo = pd.concat(lista_de_dataframes, ignore_index=True)

    # 4. Convertimos el DataFrame a un formato simple y limpio: una lista de listas.
    #    Esto evita problemas con tipos de datos como NaN.
    lista_de_listas = df_completo.where(pd.notnull(df_completo), None).values.tolist()
    
    # 5. Imprimimos el resultado como un string JSON garantizado, limpio y válido.
    #    ensure_ascii=False es la clave para manejar correctamente acentos y 'ñ'.
    print(json.dumps(lista_de_listas, ensure_ascii=False))
    
    sys.exit(0)

except Exception as e:
    # Si algo sale mal, devolvemos un JSON con el mensaje de error.
    error_message = {"error": str(e)}
    print(json.dumps(error_message, ensure_ascii=False), file=sys.stderr)
    sys.exit(1)