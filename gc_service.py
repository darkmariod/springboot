from google.oauth2 import service_account
from googleapiclient.discovery import build
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
import os, json, base64

TZ = ZoneInfo("America/Guayaquil")

class GoogleService:
    def __init__(self, creds_file="credentials.json"):

        creds_b64 = os.getenv("GOOGLE_CREDENTIALS_JSON_B64")

        if creds_b64:
            # 🔥 Render: convertir Base64 → JSON
            decoded = base64.b64decode(creds_b64).decode()
            info = json.loads(decoded)
            creds = service_account.Credentials.from_service_account_info(
                info,
                scopes=["https://www.googleapis.com/auth/calendar"]
            )
        else:
            # 🔥 Local: usar credentials.json
            creds = service_account.Credentials.from_service_account_file(
                creds_file,
                scopes=["https://www.googleapis.com/auth/calendar"]
            )

        self.service = build("calendar", "v3", credentials=creds)

    def crear_evento(self, calendar_id, resumen, descripcion, inicio, fin):
        evento = {
            "summary": resumen,
            "description": descripcion,
            "start": {"dateTime": inicio.isoformat(), "timeZone": "America/Guayaquil"},
            "end": {"dateTime": fin.isoformat(), "timeZone": "America/Guayaquil"},
            "reminders": {"useDefault": True},
        }
        self.service.events().insert(calendarId=calendar_id, body=evento).execute()
