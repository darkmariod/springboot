import streamlit as st
from streamlit_option_menu import option_menu
from datetime import datetime, timedelta
from gc_service import GoogleService
import base64
import os

# --------------------------------------------
# CALENDAR
# --------------------------------------------
CREDENTIALS = "credentials.json"
CALENDAR_ID = "TU_CALENDAR_ID_AQUI"    # ejemplo: abc123@group.calendar.google.com

gc = GoogleService(CREDENTIALS)

# --------------------------------------------
# BASE64 FUNCTION
# --------------------------------------------
def img_to_b64(path):
    with open(path, "rb") as img:
        return base64.b64encode(img.read()).decode()

# --------------------------------------------
# CSS
# --------------------------------------------
def load_css(path):
    with open(path) as f:
        st.markdown(f"<style>{f.read()}</style>", unsafe_allow_html=True)

st.set_page_config(page_title="Seven Barber Club", page_icon="✂️", layout="centered")
load_css("css/style.css")

# --------------------------------------------
# HEADER
# --------------------------------------------
st.image("assets/banner.png")
st.title("Seven Barber Club")
st.text("📍 Av. Unidad Nacional entre Juan Montalvo y Carabobo")

# --------------------------------------------
# MENU
# --------------------------------------------
selected = option_menu(
    menu_title=None,
    options=["Reservar", "Portafolio", "Aprendiz", "Detalles", "Reseñas"],
    icons=["calendar-check", "scissors", "person-workspace", "pin", "chat-dots"],
    orientation="horizontal",
)

# ============================================================
# RESERVAR
# ============================================================
if selected == "Reservar":

    st.subheader("✂️ Reserva tu cita (pago obligatorio)")

    col1, col2 = st.columns(2)
    nombre = col1.text_input("Tu Nombre *")
    whatsapp = col2.text_input("WhatsApp *")
    email = col1.text_input("Email (opcional)")
    fecha = col2.date_input("Fecha *")

    # generar horas libres reales
    horas_libres = gc.obtener_horas_disponibles(CALENDAR_ID, fecha)

    hora = col2.selectbox("Hora disponible *", horas_libres)

    servicios = {
        "Perfil de cejas": 1,
        "Afeitado / Barba": 3,
        "Corte Clásico máquina": 5,
        "Corte Clásico tijera": 5,
        "Freestyle": 7,
        "Semi Ondulado": 20,
        "VIP": 8,
        "Aprendiz (Mario)": 2
    }

    servicio = col1.selectbox("Servicio *", [""] + list(servicios.keys()))
    nota = col1.text_area("Nota (opcional)")

    barbero = col2.selectbox("Barbero *", ["", "💈 Josué", "💈 Ariel", "🧪 Aprendiz"])

    if "mostrar_qr" not in st.session_state:
        st.session_state["mostrar_qr"] = False
    if "pagado" not in st.session_state:
        st.session_state["pagado"] = False

    if st.button("Reservar"):
        if not nombre or not whatsapp or servicio == "" or barbero == "":
            st.warning("⚠ Llena todos los campos obligatorios.")
        else:
            if barbero == "🧪 Aprendiz":
                st.session_state["pagado"] = True
            else:
                st.session_state["mostrar_qr"] = True

    # QR
    if st.session_state["mostrar_qr"] and not st.session_state["pagado"]:
        precio = servicios[servicio]
        st.markdown(f"""
        ### 💳 Pagar ahora
        <div class="qr-box">
            <h4>Total a pagar: {precio}.00 USD</h4>
            <p>Escanea el QR para confirmar la cita.</p>
        </div>
        """, unsafe_allow_html=True)
        st.image("assets/qr_pago.png", width=260)

        if st.button("✔ Ya pagué"):
            st.session_state["pagado"] = True

    # CREAR EVENTO
    if st.session_state["pagado"]:
        inicio = datetime.combine(fecha, datetime.strptime(hora, "%H:%M").time())
        fin = inicio + timedelta(hours=1)

        descripcion = (
            f"Cliente: {nombre}\n"
            f"WhatsApp: {whatsapp}\n"
            f"Email: {email}\n"
            f"Servicio: {servicio}\n"
            f"Barbero: {barbero}\n"
            f"Nota: {nota}"
        )

        gc.crear_evento(
            calendar_id=CALENDAR_ID,
            resumen=f"Reserva {servicio} - {nombre}",
            descripcion=descripcion,
            inicio=inicio,
            fin=fin
        )

        st.success("✅ Reserva creada con éxito.")
        st.balloons()

        st.session_state["mostrar_qr"] = False
        st.session_state["pagado"] = False


# ============================================================
# PORTAFOLIO
# ============================================================
if selected == "Portafolio":

    st.subheader("📸 Portafolio — Trabajos reales")

    perfil_j = img_to_b64("assets/josue-perfil.jpg")
    st.markdown(f"""
    <div class="perfil-barbero">
        <img class="perfil-avatar" src="data:image/jpeg;base64,{perfil_j}">
        <h3>👑 Josué</h3>
    </div>
    """, unsafe_allow_html=True)

    cols = st.columns(3)
    for c, i in zip(cols, ["assets/corte-1.jpg","assets/corte-2.jpg","assets/corte-3.jpg"]):
        c.image(i, use_container_width=True)

    st.markdown("<hr>", unsafe_allow_html=True)

    perfil_a = img_to_b64("assets/ariel-perfil.jpg")
    st.markdown(f"""
    <div class="perfil-barbero">
        <img class="perfil-avatar" src="data:image/jpeg;base64,{perfil_a}">
        <h3>💈 Ariel</h3>
    </div>
    """, unsafe_allow_html=True)

    cols = st.columns(3)
    for c, i in zip(cols, ["assets/corte-4.jpg","assets/corte-5.jpg","assets/corte-6.jpg"]):
        c.image(i, use_container_width=True)


# ============================================================
# APRENDIZ
# ============================================================
if selected == "Aprendiz":
    st.subheader("💈 Aprendiz — Mario")
    st.markdown("""
    Cortes de práctica profesional<br>
    Precio: 2 USD<br>
    Horario: 16:00 a 20:00
    """, unsafe_allow_html=True)


# ============================================================
# DETALLES
# ============================================================
if selected == "Detalles":
    st.image("assets/map.jpg", use_container_width=True)
    st.markdown("📌 Av. Unidad Nacional — Riobamba")


# ============================================================
# RESEÑAS
# ============================================================
if selected == "Reseñas":
    st.subheader("💬 Opiniones reales")
    st.image("assets/review-1.png")
    st.image("assets/review-2.png")
