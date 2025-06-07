require('dotenv').config();
const express = require('express');
const sendMail = require('./mailer');
const sendWebhook = require('./webhook');

const app = express();
app.use(express.json());

app.post('/alert', async (req, res) => {
  const { type, message, target } = req.body;

  try {
    await sendMail(type, message, target);
    await sendWebhook(type, message, target);
    res.status(200).send('Alerts sent!');
  } catch (err) {
    console.error(err);
    res.status(500).send('Failed to send alerts');
  }
});

const PORT = process.env.PORT || 3002;
app.listen(PORT, () => console.log(`Notification service running on port ${PORT}`));
