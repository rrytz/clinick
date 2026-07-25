<?php
/**
 * Training phrases and canned responses per language.
 * Ported 1:1 from the original TypeScript intents.ts.
 * Extend TRAINING_DATA and RESPONSES together — add examples here as you
 * collect real transcripts; accuracy scales with training volume.
 */

return [
    'training_data' => [
        'en' => [
            ['text' => 'hello', 'intent' => 'greeting'],
            ['text' => 'hi there', 'intent' => 'greeting'],
            ['text' => 'good morning', 'intent' => 'greeting'],
            ['text' => 'is anyone there', 'intent' => 'greeting'],

            ['text' => 'I want to book an appointment', 'intent' => 'book_appointment'],
            ['text' => 'can I schedule a checkup', 'intent' => 'book_appointment'],
            ['text' => 'how do I set an appointment with a doctor', 'intent' => 'book_appointment'],
            ['text' => 'I need to see a doctor next week', 'intent' => 'book_appointment'],
            ['text' => 'book a consultation', 'intent' => 'book_appointment'],

            ['text' => 'I need to move my appointment', 'intent' => 'reschedule_appointment'],
            ['text' => 'can I change my appointment date', 'intent' => 'reschedule_appointment'],
            ['text' => 'reschedule my checkup', 'intent' => 'reschedule_appointment'],
            ['text' => 'I want a different appointment time', 'intent' => 'reschedule_appointment'],

            ['text' => 'cancel my appointment', 'intent' => 'cancel_appointment'],
            ['text' => 'I want to cancel my booking', 'intent' => 'cancel_appointment'],
            ['text' => 'please remove my scheduled visit', 'intent' => 'cancel_appointment'],

            ['text' => 'what time does the clinic open', 'intent' => 'clinic_hours'],
            ['text' => 'what are your operating hours', 'intent' => 'clinic_hours'],
            ['text' => 'when are you open', 'intent' => 'clinic_hours'],
            ['text' => 'are you open on weekends', 'intent' => 'clinic_hours'],

            ['text' => 'what services do you offer', 'intent' => 'services_offered'],
            ['text' => 'do you have a pediatrician', 'intent' => 'services_offered'],
            ['text' => 'what departments are available', 'intent' => 'services_offered'],
            ['text' => 'do you do lab tests', 'intent' => 'services_offered'],

            ['text' => 'I have a fever and headache', 'intent' => 'check_symptoms'],
            ['text' => 'what could cause chest pain', 'intent' => 'check_symptoms'],
            ['text' => "I've been coughing for a week", 'intent' => 'check_symptoms'],
            ['text' => 'check my symptoms', 'intent' => 'check_symptoms'],
            ['text' => 'what illness do I have', 'intent' => 'check_symptoms'],

            ['text' => 'I want to talk to a real person', 'intent' => 'talk_to_staff'],
            ['text' => 'connect me to staff', 'intent' => 'talk_to_staff'],
            ["text" => "this bot isn't helping, get me a human", 'intent' => 'talk_to_staff'],

            ['text' => 'thank you goodbye', 'intent' => 'farewell'],
            ["text" => "that's all, bye", 'intent' => 'farewell'],
            ['text' => 'thanks for the help', 'intent' => 'farewell'],
        ],
        'fil' => [
            ['text' => 'kumusta', 'intent' => 'greeting'],
            ['text' => 'hello po', 'intent' => 'greeting'],
            ['text' => 'magandang umaga po', 'intent' => 'greeting'],
            ['text' => 'may tao po ba dito', 'intent' => 'greeting'],

            ['text' => 'gusto ko pong mag-book ng appointment', 'intent' => 'book_appointment'],
            ['text' => 'paano po mag-set ng appointment sa doktor', 'intent' => 'book_appointment'],
            ['text' => 'pwede po bang magpa-checkup', 'intent' => 'book_appointment'],
            ['text' => 'gusto ko po sumangguni sa doktor', 'intent' => 'book_appointment'],

            ['text' => 'gusto ko pong ilipat ang appointment ko', 'intent' => 'reschedule_appointment'],
            ['text' => 'pwede bang baguhin ang petsa ng appointment', 'intent' => 'reschedule_appointment'],
            ['text' => 'i-reschedule po ang aking checkup', 'intent' => 'reschedule_appointment'],

            ['text' => 'gusto ko pong kanselahin ang appointment', 'intent' => 'cancel_appointment'],
            ['text' => 'i-cancel niyo po ang booking ko', 'intent' => 'cancel_appointment'],
            ['text' => 'hindi ko na po kailangan ang appointment ko', 'intent' => 'cancel_appointment'],

            ['text' => 'anong oras po kayo bukas', 'intent' => 'clinic_hours'],
            ['text' => 'ano po ang oras ng operasyon niyo', 'intent' => 'clinic_hours'],
            ['text' => 'bukas po ba kayo sa weekend', 'intent' => 'clinic_hours'],

            ['text' => 'anong mga serbisyo po meron kayo', 'intent' => 'services_offered'],
            ['text' => 'may pediatrician po ba kayo', 'intent' => 'services_offered'],
            ['text' => 'meron po ba kayong lab test', 'intent' => 'services_offered'],

            ['text' => 'may lagnat po ako at sumasakit ang ulo', 'intent' => 'check_symptoms'],
            ['text' => 'anong sakit po kaya ito', 'intent' => 'check_symptoms'],
            ['text' => 'isang linggo na pong umuubo ako', 'intent' => 'check_symptoms'],
            ['text' => 'i-check niyo po ang sintomas ko', 'intent' => 'check_symptoms'],

            ['text' => 'gusto ko pong makausap ang totoong tao', 'intent' => 'talk_to_staff'],
            ['text' => 'pakikonekta po ako sa staff', 'intent' => 'talk_to_staff'],
            ['text' => 'wala pong tulong ang bot, kailangan ko ng tao', 'intent' => 'talk_to_staff'],

            ['text' => 'salamat po, paalam', 'intent' => 'farewell'],
            ['text' => 'okay lang po, salamat', 'intent' => 'farewell'],
        ],
        'ceb' => [
            ['text' => 'kumusta', 'intent' => 'greeting'],
            ['text' => 'maayong buntag', 'intent' => 'greeting'],
            ['text' => 'naa bay tao diri', 'intent' => 'greeting'],

            ['text' => 'gusto ko mag-book og appointment', 'intent' => 'book_appointment'],
            ['text' => 'unsaon pag-set og appointment sa doktor', 'intent' => 'book_appointment'],
            ['text' => 'pwede ba ko magpa-checkup', 'intent' => 'book_appointment'],

            ['text' => 'gusto ko usbon ang akong appointment', 'intent' => 'reschedule_appointment'],
            ['text' => 'pwede ba usbon ang petsa sa appointment', 'intent' => 'reschedule_appointment'],
            ['text' => 'i-reschedule ang akong checkup', 'intent' => 'reschedule_appointment'],

            ['text' => 'gusto ko kanselahon ang appointment', 'intent' => 'cancel_appointment'],
            ['text' => 'kanselaha ang akong booking', 'intent' => 'cancel_appointment'],

            ['text' => 'unsa oras mo mo-abre', 'intent' => 'clinic_hours'],
            ['text' => 'unsa ang oras sa operasyon ninyo', 'intent' => 'clinic_hours'],
            ['text' => 'abre mo sa weekend', 'intent' => 'clinic_hours'],

            ['text' => 'unsa nga mga serbisyo naa mo', 'intent' => 'services_offered'],
            ['text' => 'naa mo pediatrician', 'intent' => 'services_offered'],
            ['text' => 'naa moy lab test', 'intent' => 'services_offered'],

            ['text' => 'gihilanat ko ug sakit ang akong ulo', 'intent' => 'check_symptoms'],
            ['text' => 'unsa kaha ni nga sakit', 'intent' => 'check_symptoms'],
            ['text' => 'usa ka semana na ko ubo', 'intent' => 'check_symptoms'],

            ['text' => 'gusto ko makigsulti sa tawo', 'intent' => 'talk_to_staff'],
            ['text' => 'ikonekta ko sa staff', 'intent' => 'talk_to_staff'],

            ['text' => 'salamat, paalam', 'intent' => 'farewell'],
            ['text' => 'sige lang, salamat', 'intent' => 'farewell'],
        ],
    ],

    'responses' => [
        'en' => [
            'greeting' => ["Hi! I'm MediBot. I can help you book appointments, check clinic info, or answer basic symptom questions. What do you need?"],
            'book_appointment' => ["Sure — let's book your appointment. Which department or doctor would you like to see, and what date works for you?"],
            'reschedule_appointment' => ["No problem, I can help reschedule. Can you share your current appointment reference number or the date it's currently booked on?"],
            'cancel_appointment' => ["Got it. Please confirm the appointment reference number you'd like to cancel."],
            'clinic_hours' => ["Our clinic is open Monday–Saturday, 8:00 AM to 6:00 PM. Emergency services are available 24/7."],
            'services_offered' => ["We offer General Medicine, Pediatrics, OB-GYN, Dental, and Laboratory services. Would you like details on a specific one?"],
            'check_symptoms' => ["I can give you a general idea based on your symptoms, but this isn't a medical diagnosis. Please describe your symptoms one at a time, or use the Symptom Checker page for a fuller assessment."],
            'talk_to_staff' => ["Connecting you to hospital staff now. Someone will be with you shortly."],
            'farewell' => ["Take care! Reach out anytime you need help with your appointments."],
            'fallback' => ["Sorry, I didn't quite catch that. I can help with booking, rescheduling, cancelling appointments, clinic hours, services, or basic symptom questions."],
        ],
        'fil' => [
            'greeting' => ["Kumusta! Ako si MediBot. Matutulungan kita mag-book ng appointment, malaman ang impormasyon ng klinika, o sagutin ang mga tanong tungkol sa sintomas. Ano po ang kailangan niyo?"],
            'book_appointment' => ["Sige po, i-book natin ang appointment niyo. Anong department o doktor po ang gusto niyong puntahan, at kailan po?"],
            'reschedule_appointment' => ["Walang problema po. Maaari niyo bang ibigay ang reference number ng kasalukuyang appointment o ang petsa nito?"],
            'cancel_appointment' => ["Sige po. Pakikumpirma na lang po ang reference number ng appointment na gusto niyong kanselahin."],
            'clinic_hours' => ["Bukas po kami Lunes hanggang Sabado, 8:00 AM hanggang 6:00 PM. May 24/7 po kaming emergency services."],
            'services_offered' => ["Mayroon po kaming General Medicine, Pediatrics, OB-GYN, Dental, at Laboratory services. May specific po ba kayong gustong malaman?"],
            'check_symptoms' => ["Makakapagbigay po ako ng general na impormasyon batay sa sintomas niyo, pero hindi po ito opisyal na diagnosis. Pakisabi po ang mga sintomas isa-isa, o gamitin ang Symptom Checker page para sa mas kumpletong assessment."],
            'talk_to_staff' => ["Ikinokonekta ko na po kayo sa staff ng ospital. Sandali lang po."],
            'farewell' => ["Ingat po! Bumalik lang po kayo kung kailangan niyo pa ng tulong."],
            'fallback' => ["Paumanhin po, hindi ko po masyadong naintindihan. Matutulungan ko kayo sa pag-book, pag-reschedule, pagkansela ng appointment, oras ng klinika, serbisyo, o mga tanong tungkol sa sintomas."],
        ],
        'ceb' => [
            'greeting' => ["Kumusta! Ako si MediBot. Makatabang ko nimo mag-book og appointment, mahibaw-an ang impormasyon sa klinika, o motubag sa mga pangutana bahin sa sintomas. Unsa may kinahanglan nimo?"],
            'book_appointment' => ["Sige, i-book nato ang imong appointment. Unsa nga department o doktor ang gusto nimo adtoon, ug kanus-a?"],
            'reschedule_appointment' => ["Walay problema. Mahatag ba nimo ang reference number sa imong kasamtangang appointment o ang petsa niini?"],
            'cancel_appointment' => ["Sige. Palihug kumpirmaha ang reference number sa appointment nga gusto nimong kanselahon."],
            'clinic_hours' => ["Bukas mi Lunes hangtod Sabado, 8:00 AM hangtod 6:00 PM. Naa moy 24/7 nga emergency services."],
            'services_offered' => ["Naa mi General Medicine, Pediatrics, OB-GYN, Dental, ug Laboratory services. Naa bay specific nga gusto nimong mahibaw-an?"],
            'check_symptoms' => ["Makahatag ko og general nga ideya base sa imong sintomas, pero dili ni opisyal nga diagnosis. Palihug isulti ang sintomas usa-usa, o gamita ang Symptom Checker page para sa mas kompleto nga assessment."],
            'talk_to_staff' => ["Ikonekta ka namo sa staff sa ospital. Hulat lang gamay."],
            'farewell' => ["Amping! Balik ra kung naa kay kinahanglan nga tabang."],
            'fallback' => ["Pasensya, wala nako klaro nasabtan. Makatabang ko sa pag-book, pag-reschedule, pagkansela og appointment, oras sa klinika, serbisyo, o mga pangutana bahin sa sintomas."],
        ],
    ],
];
