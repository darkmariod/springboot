from google.oauth2 import service_account
from googleapiclient.discovery import build
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
import os, json

TZ = ZoneInfo("America/Guayaquil")

class GoogleService:
    def __init__(self, creds_file: str = "credentials.json"):

        # --------------------------------------------
        # 1) Intentar cargar credenciales desde variable de entorno (Render)
        # --------------------------------------------
        creds_env = os.getenv("GOOGLE_CREDENTIALS_JSON")

        if creds_env:
            try:
                # Render pone saltos de línea como \n → corregimos
                creds_env = creds_env.replace("\\n", "\n")
                info = json.loads(creds_env)

                creds = service_account.Credentials.from_service_account_info(
                    info,
                    scopes=["https://www.googleapis.com/auth/calendar"]
                )
                print("🔐 Credenciales cargadas desde GOOGLE_CREDENTIALS_JSON (Render).")

            except Exception as e:
                raise Exception(f"❌ Error leyendo GOOGLE_CREDENTIALS_JSON: {e}")

        else:
            # --------------------------------------------
            # 2) Local (credentials.json)
            # --------------------------------------------
            try:
                creds = service_account.Credentials.from_service_account_file(
                    creds_file,
                    scopes=["https://www.googleapis.com/auth/calendar"]
                )
                print("📄 Usando credentials.json local.")

            except Exception as e:
                raise Exception(
                    f"❌ No se pudo cargar credentials.json.\nError: {e}\n"
                    f"Si estás en Render, debes configurar GOOGLE_CREDENTIALS_JSON."
                )

        # Crear cliente de Google Calendar
        self.service = build("calendar", "v3", credentials=creds)

    # ----------------------------------------------------------------------
    # GENERAR HORARIOS DISPONIBLES (evita horas duplicadas)
    # ----------------------------------------------------------------------
    def generar_slots_libres(self, calendar_id: str, fecha: datetime, duracion_min: int):
        try:
            start_day = datetime(fecha.year, fecha.month, fecha.day, 9, 0, tzinfo=TZ)
            end_day = datetime(fecha.year, fecha.month, fecha.day, 20, 0, tzinfo=TZ)

            step = timedelta(minutes=30)
            horas = []

            # Consultar eventos del día
            events = (
                self.service.events()
                .list(
                    calendarId=calendar_id,
                    timeMin=start_day.isoformat(),
                    timeMax=end_day.isoformat(),
                    singleEvents=True,
                    orderBy="startTime",
                )
                .execute()
                .get("items", [])
            )

            ocupados = []
            for e in events:
                s = e["start"].get("dateTime")
                f = e["end"].get("dateTime")

                if s and f:
                    s_dt = datetime.fromisoformat(s.replace("Z", "+00:00"))
                    f_dt = datetime.fromisoformat(f.replace("Z", "+00:00"))
                    ocupados.append((s_dt, f_dt))

            current = start_day

            # Generar horas libres evitando horas ocupadas
            while current + timedelta(minutes=duracion_min) <= end_day:
                libre = True

                for (s, f) in ocupados:
                    if s <= current < f:
                        libre = False
                        break

                if libre:
                    horas.append(current.strftime("%H:%M"))

                current += step

            return horas

        except Exception as e:
            print("❌ Error generando horarios:", e)
            return []

    # ----------------------------------------------------------------------
    # CREAR EVENTO EN GOOGLE CALENDAR
    # ----------------------------------------------------------------------
    def crear_evento(self, calendar_id, resumen, descripcion, inicio, fin, timezone="America/Guayaquil"):
        try:
            evento = {
                "summary": resumen,
                "description": descripcion,
                "start": {"dateTime": inicio.isoformat(), "timeZone": timezone},
                "end": {"dateTime": fin.isoformat(), "timeZone": timezone},
            }

            self.service.events().insert(calendarId=calendar_id, body=evento).execute()
            print(f"✅ Evento creado correctamente: {resumen}")

        except Exception as e:
            raise Exception(f"❌ Error creando evento en Google Calendar: {e}")
