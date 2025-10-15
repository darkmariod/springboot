import { motion } from "framer-motion";

export default function Invitacion() {
  // ✅ WhatsApp configurado
  const whatsappNumber = "593939985195"; // Sin el signo +
  const whatsappMessage = "¡Hola Henry! Confirmo mi asistencia a tu cumpleaños el 18 de Octubre.";
  const whatsappLink = `https://wa.me/${whatsappNumber}?text=${encodeURIComponent(whatsappMessage)}`;

  // 📍 Enlace de ubicación
  const locationLink = "https://maps.app.goo.gl/mCSDgiFuCtqQyZPN6";

  return (
    <section
      style={{
        minHeight: "100vh",
        width: "100%",
        position: "relative",
        overflow: "hidden",
        display: "flex",
        flexDirection: "column",
        justifyContent: "center",
        alignItems: "center",
        textAlign: "center",
        fontFamily: "'Montserrat Alternates', sans-serif",
        color: "#fff",
        backgroundColor: "#000",
        backgroundImage: 'url("/image-1.jpg")', // o reemplázalo con tu video más adelante
        backgroundSize: "cover",
        backgroundPosition: "center",
        backgroundRepeat: "no-repeat",
        padding: "0 1rem",
      }}
    >
      {/* 🖤 Capa oscura */}
      <div
        style={{
          position: "absolute",
          top: 0,
          left: 0,
          width: "100%",
          height: "100%",
          background: "rgba(0, 0, 0, 0.65)",
          zIndex: -1,
        }}
      ></div>

      {/* ✨ Título principal */}
      <motion.h1
        initial={{ opacity: 0, y: -60 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 1 }}
        className="gold-text"
      >
        Te invito a celebrar mi cumpleaños
      </motion.h1>

      {/* 🎉 Nombre */}
      <motion.h2
        initial={{ opacity: 0, scale: 0.7 }}
        animate={{ opacity: 1, scale: 1 }}
        transition={{ delay: 0.6, type: "spring" }}
        className="name"
      >
        Henry | Barber
      </motion.h2>

      {/* 🎊 Fecha y hora */}
      <motion.p
        initial={{ opacity: 0, y: 50 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 1.2, duration: 1 }}
        className="celebrar"
      >
        Sábado <strong>18 de Octubre a las 21:00</strong>
      </motion.p>

      {/* 🟢 Confirmar por WhatsApp */}
      <motion.a
        href={whatsappLink}
        target="_blank"
        rel="noopener noreferrer"
        initial={{ opacity: 0, y: 30 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 2.2, duration: 0.8 }}
        whileHover={{
          scale: 1.05,
          boxShadow: "0px 0px 15px rgba(37, 211, 102, 0.8)",
        }}
        whileTap={{ scale: 0.95 }}
        className="whatsapp-button"
      >
        Confirmar por WhatsApp
      </motion.a>

      {/* 📍 Ver ubicación */}
      <motion.a
        href={locationLink}
        target="_blank"
        rel="noopener noreferrer"
        initial={{ opacity: 0, y: 30 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 2.5, duration: 0.8 }}
        whileHover={{
          scale: 1.05,
          boxShadow: "0px 0px 15px rgba(66, 133, 244, 0.8)",
        }}
        whileTap={{ scale: 0.95 }}
        className="location-button"
      >
        Ver Ubicación
      </motion.a>

      <style>
        {`
          @import url('https://fonts.googleapis.com/css2?family=Montserrat+Alternates:wght@500;700;900&display=swap');

          .gold-text {
            font-size: clamp(2.5rem, 8vw, 4rem);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 4px;
            background: linear-gradient(90deg, #b8860b, #ffd700, #fff8dc, #b8860b);
            background-size: 400%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: shine 6s linear infinite;
            margin-bottom: 0.5rem;
          }

          .name {
            font-size: clamp(3.5rem, 10vw, 5rem);
            font-weight: 900;
            background: linear-gradient(90deg, #ffd700, #fff8dc, #ffea00);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 30px rgba(255, 215, 0, 0.4);
            letter-spacing: 2px;
            margin-bottom: 1.5rem;
          }

          .celebrar {
            font-size: clamp(2rem, 8vw, 3.2rem);
            font-weight: 700;
            color: #fff;
            text-shadow: 0 0 20px rgba(0,0,0,0.8);
            letter-spacing: 1px;
            margin-bottom: 1rem;
          }

          .whatsapp-button,
          .location-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 1rem 2rem;
            margin-top: 1rem;
            border-radius: 50px;
            font-size: clamp(1rem, 4vw, 1.2rem);
            font-weight: 700;
            text-decoration: none;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0,0,0,0.4);
            min-width: 220px;
          }

          .whatsapp-button {
            background-color: #25D366;
            text-shadow: 0 0 5px rgba(0,0,0,0.3);
          }

          .location-button {
            background-color: #4285F4;
            text-shadow: 0 0 5px rgba(0,0,0,0.3);
          }

          @keyframes shine {
            0% { background-position: 0%; }
            100% { background-position: 100%; }
          }
        `}
      </style>
    </section>
  );
}
