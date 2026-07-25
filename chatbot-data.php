<?php
/**
 * CLINICK MediBot — Training phrases and canned responses per language.
 * Extend TRAINING_DATA and RESPONSES together — more examples = better accuracy.
 */

return [
    'training_data' => [
        'en' => [
            // Greetings — many short-form variants so single words score high
            ['text' => 'hello',                              'intent' => 'greeting'],
            ['text' => 'hi',                                 'intent' => 'greeting'],
            ['text' => 'hey',                                'intent' => 'greeting'],
            ['text' => 'hi there',                           'intent' => 'greeting'],
            ['text' => 'hello there',                        'intent' => 'greeting'],
            ['text' => 'good morning',                       'intent' => 'greeting'],
            ['text' => 'good afternoon',                     'intent' => 'greeting'],
            ['text' => 'good evening',                       'intent' => 'greeting'],
            ['text' => 'is anyone there',                    'intent' => 'greeting'],
            ['text' => 'anyone home',                        'intent' => 'greeting'],
            ['text' => 'start',                              'intent' => 'greeting'],
            ['text' => 'help',                               'intent' => 'greeting'],
            ['text' => 'hi bot',                             'intent' => 'greeting'],

            // Book appointment
            ['text' => 'book',                               'intent' => 'book_appointment'],
            ['text' => 'book appointment',                   'intent' => 'book_appointment'],
            ['text' => 'I want to book an appointment',      'intent' => 'book_appointment'],
            ['text' => 'can I schedule a checkup',           'intent' => 'book_appointment'],
            ['text' => 'how do I set an appointment with a doctor', 'intent' => 'book_appointment'],
            ['text' => 'I need to see a doctor next week',   'intent' => 'book_appointment'],
            ['text' => 'book a consultation',                'intent' => 'book_appointment'],
            ['text' => 'schedule appointment',               'intent' => 'book_appointment'],
            ['text' => 'I need an appointment',              'intent' => 'book_appointment'],
            ['text' => 'make an appointment',                'intent' => 'book_appointment'],
            ['text' => 'I want to see a doctor',             'intent' => 'book_appointment'],
            ['text' => 'new appointment',                    'intent' => 'book_appointment'],
            ['text' => 'set up a visit',                     'intent' => 'book_appointment'],

            // Reschedule
            ['text' => 'reschedule',                         'intent' => 'reschedule_appointment'],
            ['text' => 'I need to move my appointment',      'intent' => 'reschedule_appointment'],
            ['text' => 'can I change my appointment date',   'intent' => 'reschedule_appointment'],
            ['text' => 'reschedule my checkup',              'intent' => 'reschedule_appointment'],
            ['text' => 'I want a different appointment time','intent' => 'reschedule_appointment'],
            ['text' => 'move my appointment',                'intent' => 'reschedule_appointment'],
            ['text' => 'change appointment',                 'intent' => 'reschedule_appointment'],
            ['text' => 'shift my schedule',                  'intent' => 'reschedule_appointment'],

            // Cancel
            ['text' => 'cancel',                             'intent' => 'cancel_appointment'],
            ['text' => 'cancel my appointment',              'intent' => 'cancel_appointment'],
            ['text' => 'I want to cancel my booking',        'intent' => 'cancel_appointment'],
            ['text' => 'please remove my scheduled visit',   'intent' => 'cancel_appointment'],
            ['text' => 'cancel booking',                     'intent' => 'cancel_appointment'],
            ['text' => 'I need to cancel',                   'intent' => 'cancel_appointment'],

            // Clinic hours
            ['text' => 'hours',                              'intent' => 'clinic_hours'],
            ['text' => 'clinic hours',                       'intent' => 'clinic_hours'],
            ['text' => 'what time does the clinic open',     'intent' => 'clinic_hours'],
            ['text' => 'what are your operating hours',      'intent' => 'clinic_hours'],
            ['text' => 'when are you open',                  'intent' => 'clinic_hours'],
            ['text' => 'are you open on weekends',           'intent' => 'clinic_hours'],
            ['text' => 'open hours',                         'intent' => 'clinic_hours'],
            ['text' => 'closing time',                       'intent' => 'clinic_hours'],
            ['text' => 'office hours',                       'intent' => 'clinic_hours'],
            ['text' => 'schedule',                           'intent' => 'clinic_hours'],

            // Services
            ['text' => 'services',                           'intent' => 'services_offered'],
            ['text' => 'what services do you offer',         'intent' => 'services_offered'],
            ['text' => 'do you have a pediatrician',         'intent' => 'services_offered'],
            ['text' => 'what departments are available',     'intent' => 'services_offered'],
            ['text' => 'do you do lab tests',                'intent' => 'services_offered'],
            ['text' => 'what can you treat',                 'intent' => 'services_offered'],
            ['text' => 'medical services',                   'intent' => 'services_offered'],
            ['text' => 'doctors available',                  'intent' => 'services_offered'],
            ['text' => 'specialties',                        'intent' => 'services_offered'],

            // Check symptoms
            ['text' => 'symptoms',                           'intent' => 'check_symptoms'],
            ['text' => 'I have a fever and headache',        'intent' => 'check_symptoms'],
            ['text' => 'what could cause chest pain',        'intent' => 'check_symptoms'],
            ['text' => "I've been coughing for a week",      'intent' => 'check_symptoms'],
            ['text' => 'check my symptoms',                  'intent' => 'check_symptoms'],
            ['text' => 'what illness do I have',             'intent' => 'check_symptoms'],
            ['text' => 'I feel sick',                        'intent' => 'check_symptoms'],
            ['text' => 'my stomach hurts',                   'intent' => 'check_symptoms'],
            ['text' => 'I have a rash',                      'intent' => 'check_symptoms'],
            ['text' => 'symptom checker',                    'intent' => 'check_symptoms'],
            ['text' => 'I am feeling unwell',                'intent' => 'check_symptoms'],

            // Talk to staff
            ['text' => 'human',                              'intent' => 'talk_to_staff'],
            ['text' => 'staff',                              'intent' => 'talk_to_staff'],
            ['text' => 'I want to talk to a real person',    'intent' => 'talk_to_staff'],
            ['text' => 'connect me to staff',                'intent' => 'talk_to_staff'],
            ['text' => "this bot isn't helping, get me a human", 'intent' => 'talk_to_staff'],
            ['text' => 'speak to someone',                   'intent' => 'talk_to_staff'],
            ['text' => 'talk to a person',                   'intent' => 'talk_to_staff'],
            ['text' => 'connect to receptionist',            'intent' => 'talk_to_staff'],

            // Farewell
            ['text' => 'bye',                                'intent' => 'farewell'],
            ['text' => 'goodbye',                            'intent' => 'farewell'],
            ['text' => 'thank you goodbye',                  'intent' => 'farewell'],
            ['text' => "that's all, bye",                    'intent' => 'farewell'],
            ['text' => 'thanks for the help',                'intent' => 'farewell'],
            ['text' => 'see you',                            'intent' => 'farewell'],
            ['text' => 'thanks',                             'intent' => 'farewell'],
            ['text' => 'done',                               'intent' => 'farewell'],
        ],

        'fil' => [
            ['text' => 'kumusta',                            'intent' => 'greeting'],
            ['text' => 'hello po',                           'intent' => 'greeting'],
            ['text' => 'magandang umaga po',                 'intent' => 'greeting'],
            ['text' => 'magandang hapon po',                 'intent' => 'greeting'],
            ['text' => 'magandang gabi po',                  'intent' => 'greeting'],
            ['text' => 'may tao po ba dito',                 'intent' => 'greeting'],
            ['text' => 'meron po ba',                        'intent' => 'greeting'],
            ['text' => 'tulungan mo ako',                    'intent' => 'greeting'],

            ['text' => 'gusto ko pong mag-book ng appointment', 'intent' => 'book_appointment'],
            ['text' => 'paano po mag-set ng appointment sa doktor', 'intent' => 'book_appointment'],
            ['text' => 'pwede po bang magpa-checkup',        'intent' => 'book_appointment'],
            ['text' => 'gusto ko po sumangguni sa doktor',   'intent' => 'book_appointment'],
            ['text' => 'mag-book po',                        'intent' => 'book_appointment'],
            ['text' => 'kailangan ko ng appointment',        'intent' => 'book_appointment'],
            ['text' => 'gusto ko po pumunta sa doktor',      'intent' => 'book_appointment'],

            ['text' => 'gusto ko pong ilipat ang appointment ko', 'intent' => 'reschedule_appointment'],
            ['text' => 'pwede bang baguhin ang petsa ng appointment', 'intent' => 'reschedule_appointment'],
            ['text' => 'i-reschedule po ang aking checkup',  'intent' => 'reschedule_appointment'],
            ['text' => 'palipatin ang appointment',          'intent' => 'reschedule_appointment'],

            ['text' => 'gusto ko pong kanselahin ang appointment', 'intent' => 'cancel_appointment'],
            ['text' => 'i-cancel niyo po ang booking ko',    'intent' => 'cancel_appointment'],
            ['text' => 'hindi ko na po kailangan ang appointment ko', 'intent' => 'cancel_appointment'],
            ['text' => 'kanselahin po',                      'intent' => 'cancel_appointment'],

            ['text' => 'anong oras po kayo bukas',           'intent' => 'clinic_hours'],
            ['text' => 'ano po ang oras ng operasyon niyo',  'intent' => 'clinic_hours'],
            ['text' => 'bukas po ba kayo sa weekend',        'intent' => 'clinic_hours'],
            ['text' => 'oras ng klinika',                    'intent' => 'clinic_hours'],
            ['text' => 'kailan po kayo bukas',               'intent' => 'clinic_hours'],

            ['text' => 'anong mga serbisyo po meron kayo',   'intent' => 'services_offered'],
            ['text' => 'may pediatrician po ba kayo',        'intent' => 'services_offered'],
            ['text' => 'meron po ba kayong lab test',        'intent' => 'services_offered'],
            ['text' => 'mga serbisyo ninyo',                 'intent' => 'services_offered'],
            ['text' => 'anong doktor meron kayo',            'intent' => 'services_offered'],

            ['text' => 'may lagnat po ako at sumasakit ang ulo', 'intent' => 'check_symptoms'],
            ['text' => 'anong sakit po kaya ito',            'intent' => 'check_symptoms'],
            ['text' => 'isang linggo na pong umuubo ako',    'intent' => 'check_symptoms'],
            ['text' => 'i-check niyo po ang sintomas ko',    'intent' => 'check_symptoms'],
            ['text' => 'may sakit po ako',                   'intent' => 'check_symptoms'],
            ['text' => 'nasakit po ang tiyan ko',            'intent' => 'check_symptoms'],
            ['text' => 'sintomas',                           'intent' => 'check_symptoms'],

            ['text' => 'gusto ko pong makausap ang totoong tao', 'intent' => 'talk_to_staff'],
            ['text' => 'pakikonekta po ako sa staff',        'intent' => 'talk_to_staff'],
            ['text' => 'wala pong tulong ang bot, kailangan ko ng tao', 'intent' => 'talk_to_staff'],
            ['text' => 'may tao po ba akong makakausap',     'intent' => 'talk_to_staff'],

            ['text' => 'salamat po, paalam',                 'intent' => 'farewell'],
            ['text' => 'okay lang po, salamat',              'intent' => 'farewell'],
            ['text' => 'paalam po',                          'intent' => 'farewell'],
            ['text' => 'salamat',                            'intent' => 'farewell'],
        ],

        'ceb' => [
            ['text' => 'kumusta',                            'intent' => 'greeting'],
            ['text' => 'maayong buntag',                     'intent' => 'greeting'],
            ['text' => 'maayong hapon',                      'intent' => 'greeting'],
            ['text' => 'naa bay tao diri',                   'intent' => 'greeting'],
            ['text' => 'tabang',                             'intent' => 'greeting'],

            ['text' => 'gusto ko mag-book og appointment',   'intent' => 'book_appointment'],
            ['text' => 'unsaon pag-set og appointment sa doktor', 'intent' => 'book_appointment'],
            ['text' => 'pwede ba ko magpa-checkup',          'intent' => 'book_appointment'],
            ['text' => 'mag-book',                           'intent' => 'book_appointment'],
            ['text' => 'gusto ko moadto sa doktor',          'intent' => 'book_appointment'],

            ['text' => 'gusto ko usbon ang akong appointment', 'intent' => 'reschedule_appointment'],
            ['text' => 'pwede ba usbon ang petsa sa appointment', 'intent' => 'reschedule_appointment'],
            ['text' => 'i-reschedule ang akong checkup',     'intent' => 'reschedule_appointment'],

            ['text' => 'gusto ko kanselahon ang appointment', 'intent' => 'cancel_appointment'],
            ['text' => 'kanselaha ang akong booking',        'intent' => 'cancel_appointment'],
            ['text' => 'kanselahin',                         'intent' => 'cancel_appointment'],

            ['text' => 'unsa oras mo mo-abre',               'intent' => 'clinic_hours'],
            ['text' => 'unsa ang oras sa operasyon ninyo',   'intent' => 'clinic_hours'],
            ['text' => 'abre mo sa weekend',                 'intent' => 'clinic_hours'],
            ['text' => 'oras sa klinika',                    'intent' => 'clinic_hours'],

            ['text' => 'unsa nga mga serbisyo naa mo',       'intent' => 'services_offered'],
            ['text' => 'naa mo pediatrician',                'intent' => 'services_offered'],
            ['text' => 'naa moy lab test',                   'intent' => 'services_offered'],

            ['text' => 'gihilanat ko ug sakit ang akong ulo', 'intent' => 'check_symptoms'],
            ['text' => 'unsa kaha ni nga sakit',             'intent' => 'check_symptoms'],
            ['text' => 'usa ka semana na ko ubo',            'intent' => 'check_symptoms'],
            ['text' => 'nasakit ko',                         'intent' => 'check_symptoms'],

            ['text' => 'gusto ko makigsulti sa tawo',        'intent' => 'talk_to_staff'],
            ['text' => 'ikonekta ko sa staff',               'intent' => 'talk_to_staff'],

            ['text' => 'salamat, paalam',                    'intent' => 'farewell'],
            ['text' => 'sige lang, salamat',                 'intent' => 'farewell'],
            ['text' => 'salamat',                            'intent' => 'farewell'],
        ],
    ],

    'responses' => [
        'en' => [
            'greeting' => [
                "Hi! I'm MediBot — CLINICK's assistant. I can help you book appointments, check clinic hours, explore services, or answer basic symptom questions. What do you need?",
                "Hello! How can I help you today? I can assist with appointments, clinic info, or symptom questions.",
            ],
            'book_appointment' => [
                "To book an appointment, go to the Appointments tab and select your preferred doctor and date. Need help navigating there?",
                "I can guide you to book a visit. Head to the Patient Schedule section and pick a doctor and time slot that works for you.",
            ],
            'reschedule_appointment' => [
                "To reschedule, go to your Appointments tab, find your booking, and use the Reschedule option. Your new queue number will be assigned automatically.",
                "Sure — find your appointment in the Appointments tab and select Reschedule. You'll be assigned a new queue number.",
            ],
            'cancel_appointment' => [
                "To cancel, go to the Appointments tab, find your scheduled visit, and select Cancel. You'll receive a confirmation once it's processed.",
                "Head to your Appointments, select the booking you'd like to remove, and click Cancel.",
            ],
            'clinic_hours' => [
                "CLINICK is open Monday–Saturday, 8:00 AM to 6:00 PM. Emergency services are available 24/7.",
                "Our operating hours are Monday to Saturday, 8 AM – 6 PM. We have 24/7 emergency coverage.",
            ],
            'services_offered' => [
                "We offer General Medicine, Pediatrics, OB-GYN, Dental, and Laboratory services. Would you like details on a specific one?",
                "CLINICK has departments for General Medicine, Pediatrics, OB-GYN, Dental, and Lab work. Which one can I tell you more about?",
            ],
            'check_symptoms' => [
                "I can provide general guidance, but I'm not a diagnostic tool. Please describe your symptoms and I'll point you in the right direction — or use the Symptom Checker tab for a fuller assessment.",
                "For a more complete review, I recommend the built-in Symptom Checker. Tell me what you're experiencing and I'll help as best I can.",
            ],
            'talk_to_staff' => [
                "Our front desk team is available Monday–Saturday, 8 AM–6 PM. You can also visit us in person or call during those hours.",
                "I'll flag your request. Our clinic staff are available during operating hours (Mon–Sat, 8 AM–6 PM) to assist you directly.",
            ],
            'farewell' => [
                "Take care! Feel free to come back anytime you need help.",
                "Goodbye! Wishing you good health. Don't hesitate to reach out if you need anything.",
            ],
            'fallback' => [
                "I didn't quite catch that. I can help with: booking, rescheduling, or cancelling appointments · clinic hours · our services · symptom questions.",
                "Hmm, I'm not sure about that one. Try asking about: appointments, clinic hours, services, or symptoms.",
            ],
        ],

        'fil' => [
            'greeting' => [
                "Kumusta! Ako si MediBot ng CLINICK. Matutulungan kita mag-book ng appointment, alamin ang oras ng klinika, mga serbisyo, o sagutin ang mga tanong tungkol sa sintomas. Ano po ang kailangan niyo?",
                "Magandang araw! Paano kita matutulungan ngayon?",
            ],
            'book_appointment' => [
                "Para mag-book ng appointment, pumunta sa tab na Appointments at pumili ng doktor at petsa na maginhawa sa iyo.",
                "Sige po, pumunta sa seksyon ng Patient Schedule at piliin ang doktor at oras na gusto niyo.",
            ],
            'reschedule_appointment' => [
                "Para mag-reschedule, hanapin ang iyong appointment sa Appointments tab at gamitin ang opsyong I-reschedule. Makakakuha ka ng bagong queue number.",
                "Hanapin ang iyong booking sa Appointments tab at i-click ang Reschedule.",
            ],
            'cancel_appointment' => [
                "Para kanselahin, pumunta sa Appointments tab, hanapin ang iyong schedule, at i-click ang Cancel.",
                "Sige po. Pumunta lang sa inyong listahan ng appointment at piliin ang I-cancel.",
            ],
            'clinic_hours' => [
                "Bukas po kami Lunes hanggang Sabado, 8:00 AM hanggang 6:00 PM. May 24/7 kami para sa emergency.",
                "Ang aming oras ng operasyon ay Lunes–Sabado, 8 AM hanggang 6 PM. May emergency services din kami anumang oras.",
            ],
            'services_offered' => [
                "Mayroon po kaming General Medicine, Pediatrics, OB-GYN, Dental, at Laboratory services. Gusto niyo bang malaman ang higit pa sa isa sa mga ito?",
                "Ang aming mga serbisyo: General Medicine, Pediatrics, OB-GYN, Dental, at Lab tests.",
            ],
            'check_symptoms' => [
                "Makakapagbigay po ako ng pangkalahatang gabay, pero hindi ako isang opisyal na diagnosis tool. Ilarawan ang iyong mga sintomas o gamitin ang Symptom Checker tab.",
                "Para sa mas kumpletong assessment, subukan ang Symptom Checker sa loob ng dashboard.",
            ],
            'talk_to_staff' => [
                "Ang aming front desk ay available Lunes–Sabado, 8 AM–6 PM. Pwede kayong bumisita nang personal o tumawag sa aming klinika.",
                "Itatala ko ang inyong kahilingan. Ang aming staff ay handa tuwing Lunes–Sabado, 8 AM–6 PM.",
            ],
            'farewell' => [
                "Ingat po! Bumalik lang kayo kung kailangan niyo pa ng tulong.",
                "Salamat at paalam! Nawa ay maging malusog kayo lagi.",
            ],
            'fallback' => [
                "Paumanhin po, hindi ko masyadong naintindihan. Matutulungan ko kayo sa: appointment, oras ng klinika, mga serbisyo, o mga katanungan tungkol sa sintomas.",
                "Hindi ko masigurado ang ibig ninyong sabihin. Subukan ang mga ito: booking, reschedule, kansela, clinic hours, o sintomas.",
            ],
        ],

        'ceb' => [
            'greeting' => [
                "Kumusta! Ako si MediBot sa CLINICK. Makatabang ko nimo mag-book og appointment, mahibaw-an ang oras sa klinika, mga serbisyo, o motubag sa mga pangutana bahin sa sintomas.",
                "Maayong adlaw! Unsaon ko ikaw pagbulig karon?",
            ],
            'book_appointment' => [
                "Para mag-book og appointment, adto sa Appointments tab ug pilia ang doktor ug petsa nga gusto nimo.",
                "Sige, adto sa Patient Schedule ug pilia ang imong doktor ug oras.",
            ],
            'reschedule_appointment' => [
                "Para mag-reschedule, pangitaa ang imong appointment sa Appointments tab ug gamita ang Reschedule nga kapilian.",
                "Pangitaa ang imong booking sa Appointments tab ug i-click ang Reschedule.",
            ],
            'cancel_appointment' => [
                "Para icancel, adto sa Appointments tab, pangitaa ang imong schedule, ug i-click ang Cancel.",
                "Sige. Adto lang sa imong lista sa appointment ug pilion ang I-cancel.",
            ],
            'clinic_hours' => [
                "Bukas mi Lunes hangtod Sabado, 8:00 AM hangtod 6:00 PM. Naa moy 24/7 nga emergency services.",
                "Ang among oras sa operasyon mao ang Lunes–Sabado, 8 AM hangtod 6 PM.",
            ],
            'services_offered' => [
                "Naa mi General Medicine, Pediatrics, OB-GYN, Dental, ug Laboratory services. Naa bay specific nga gusto nimong mahibaw-an?",
                "Among mga serbisyo: General Medicine, Pediatrics, OB-GYN, Dental, ug Lab tests.",
            ],
            'check_symptoms' => [
                "Makahatag ko og general nga ideya base sa imong sintomas, pero dili ni opisyal nga diagnosis. Gamita ang Symptom Checker tab para sa mas kompleto nga assessment.",
                "Para mas kompleto, subuka ang Symptom Checker sa dashboard.",
            ],
            'talk_to_staff' => [
                "Ang among front desk available Lunes–Sabado, 8 AM–6 PM. Pwede ka moadto personal o motawag sa klinika.",
                "Irekord nako ang imong hangyo. Ang among staff anaa tuwing Lunes–Sabado, 8 AM–6 PM.",
            ],
            'farewell' => [
                "Amping! Balik ra kung naa kay kinahanglan nga tabang.",
                "Salamat ug paalam! Naway magmaayo ka kanunay.",
            ],
            'fallback' => [
                "Pasensya, wala nako klaro nasabtan. Makatabang ko sa: appointment, oras sa klinika, mga serbisyo, o mga pangutana bahin sa sintomas.",
                "Wala ko masiguro unsay imong gipasabot. Sulayi kini: booking, reschedule, cancel, clinic hours, o sintomas.",
            ],
        ],
    ],
];
