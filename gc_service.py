from google.oauth2 import service_account
from googleapiclient.discovery import build
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
import os, json

TZ = ZoneInfo("America/Guayaquil")

class GoogleService:
    def __init__(self, creds_file="credentials.json"):

        creds_env = os.getenv("GOOGLE_CREDENTIALS_JSON")

        if creds_env:
            creds_env = creds_env.replace("\\n", "\n")
            info = json.loads(creds_env)

            creds = service_account.Credentials.from_service_account_info(
                info,
                scopes=["https://www.googleapis.com/auth/calendar"]
            )
            print("🔐 Usando credenciales desde variable de entorno.")
        else:
            creds = service_account.Credentials.from_service_account_file(
                creds_file,
                scopes=["https://www.googleapis.com/auth/calendar"]
            )
            print("📄 Usando archivo local credentials.json.")

        self.service = build("calendar", "v3", credentials=creds)


    # ============================================================
    # 📌 HORAS DISPONIBLES SIN CHOCAR — ANTI DUPLICADOS
    # ============================================================
    def obtener_horas_disponibles(self, calendar_id: str, fecha):

        hora_inicio = datetime(fecha.year, fecha.month, fecha.day, 9, 0, tzinfo=TZ)
        hora_fin    = datetime(fecha.year, fecha.month, fecha.day, 20, 0, tzinfo=TZ)
        step        = timedelta(minutes=60)

        eventos = self.service.events().list(
            calendarId=calendar_id,
            timeMin=hora_inicio.isoformat(),
            timeMax=hora_fin.isoformat(),
            singleEvents=True,
            orderBy="startTime"
        ).execute().get("items", [])

        ocupados = []

        for e in eventos:
            s = e["start"].get("dateTime")
            f = e["end"].get("dateTime")
            if s and f:
                s_dt = datetime.fromisoformat(s.replace("Z", "+00:00"))
                f_dt = datetime.fromisoformat(f.replace("Z", "+00:00"))
                ocupados.append((s_dt, f_dt))

        horas_libres = []
        actual = hora_inicio

        while actual + step <= hora_fin:
            libre = True

            for inicio_ev, fin_ev in ocupados:
                if inicio_ev <= actual < fin_ev:
                    libre = False
                    break

            if libre:
                horas_libres.append(actual.strftime("%H:%M"))

            actual += step

        return horas_libres


    # ============================================================
    # 📌 CREAR EVENTO
    # ============================================================
    def crear_evento(self, calendar_id, resumen, descripcion, inicio, fin, timezone="America/Guayaquil"):
        evento = {
            "summary": resumen,
            "description": descripcion,
            "start": {"dateTime": inicio.isoformat(), "timeZone": timezone},
            "end": {"dateTime": fin.isoformat(), "timeZone": timezone},
        }

        self.service.events().insert(calendarId=calendar_id, body=evento).execute()
        print(f"✅ Evento creado: {resumen}")
