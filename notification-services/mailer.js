const nodemailer = require('nodemailer');

// Make sure to load environment variables (if you use dotenv)
require('dotenv').config();

const transporter = nodemailer.createTransport({
  host: "smtp.mailtrap.io",
  port: 587, // your chosen port
  auth: {
    user: process.env.MAIL_USER,  // matches MAIL_USER in .env
    pass: process.env.MAIL_PASS   // matches MAIL_PASS in .env
  }
});

module.exports = function sendMail(type, message, target) {
  const mailOptions = {
    from: '"MonitorBot" <alerts@monitor.com>',
    to: "admin@example.com", // change recipient if needed
    subject: `⚠️ ${type.toUpperCase()} Alert`,
    text: `Target: ${target}\n\n${message}`
  };

  return transporter.sendMail(mailOptions);
};
