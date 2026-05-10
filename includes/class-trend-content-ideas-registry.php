<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Registry {
    public static function defaults() {
        return array(
            self::src('skyscanner','Skyscanner',1,'metasearch voli','skyscanner.it,skyscanner.net',7,1,'Analizza trend su voli, destinazioni emergenti, prezzi e stagionalità citati da Skyscanner.','Trend voli e destinazioni ricercate.'),
            self::src('booking','Booking.com',1,'hotel e domanda alloggi','booking.com,news.booking.com,globalnews.booking.com',7,1,'Evidenzia trend su alloggi, destinazioni e comportamenti di prenotazione Booking.com.','Trend alloggi e prenotazioni.'),
            self::src('expedia','Expedia',1,'OTA e pacchetti','expedia.com,expediagroup.com',7,1,'Cerca segnali Expedia su pacchetti, voli+hotel, famiglie e domanda internazionale.','Trend OTA e pacchetti viaggio.'),
            self::src('hotels','Hotels.com',1,'hotel','hotels.com,expediagroup.com',10,1,'Analizza trend Hotels.com su hotel, servizi richiesti e destinazioni per soggiorni brevi.','Trend hotel e soggiorni.'),
            self::src('vrbo','Vrbo',1,'case vacanza','vrbo.com,expediagroup.com',10,1,'Individua trend Vrbo su case vacanza, gruppi, famiglie e soggiorni lunghi.','Trend case vacanza.'),
            self::src('airbnb_newsroom','Airbnb Newsroom',1,'case vacanza ed esperienze','airbnb.com,news.airbnb.com',7,1,'Analizza comunicati e report Airbnb su mete, esperienze e bisogni degli ospiti.','Newsroom Airbnb e trend ospitalità.'),
            self::src('tripadvisor','Tripadvisor',1,'recensioni e ispirazione','tripadvisor.com,tripadvisor.mediaroom.com',7,1,'Trova trend Tripadvisor su attrazioni, ristorazione, destinazioni e preferenze viaggiatori.','Trend da recensioni e classifiche.'),
            self::src('etc','European Travel Commission',1,'istituzionale Europa','etc-corporate.org,visiteurope.com',14,1,'Usa dati ETC per trend europei, mercati inbound e stagionalità.','Dati e report turismo Europa.'),
            self::src('google_travel','Google Travel',1,'ricerche viaggio','google.com,travel.google',7,1,'Evidenzia segnali Google Travel su destinazioni, itinerari e bisogni di pianificazione.','Segnali Google Travel.'),
            self::src('google_flights','Google Flights',1,'voli','google.com',7,1,'Analizza trend voli, prezzo, periodi e collegamenti utili ai viaggiatori italiani.','Segnali Google Flights.'),
            self::src('lonely_planet','Lonely Planet',1,'editoriale travel','lonelyplanet.com',10,1,'Individua temi editoriali e destinazioni emergenti coerenti con guide pratiche.','Trend editoriali Lonely Planet.'),
            self::src('time_out','Time Out',1,'city guide ed esperienze','timeout.com',10,1,'Cerca trend su città, quartieri, eventi e esperienze urbane monetizzabili.','Trend città e lifestyle travel.'),
            self::src('travel_leisure','Travel + Leisure',1,'editoriale travel premium','travelandleisure.com',10,1,'Analizza trend su destinazioni, hotel, esperienze e liste editoriali internazionali.','Trend editoriali internazionali.'),
            self::src('enit','ENIT',2,'istituzionale Italia','enit.it,italia.it',14,1,'Usa dati ENIT su turismo Italia, domanda estera e campagne destinazione.','Dati turismo Italia.'),
            self::src('istat','ISTAT',2,'statistiche Italia','istat.it',30,1,'Cerca dati ISTAT recenti su viaggi, presenze, arrivi e spesa turistica.','Statistiche ufficiali Italia.'),
            self::src('eurostat','Eurostat',2,'statistiche Europa','ec.europa.eu,eurostat.ec.europa.eu',30,1,'Usa dataset e news Eurostat per segnali macro sul turismo europeo.','Statistiche ufficiali UE.'),
            self::src('un_tourism','UN Tourism',2,'istituzionale globale','unwto.org,un-tourism.org',30,1,'Analizza barometri e report UN Tourism per trend globali verificabili.','Dati turismo globale.'),
            self::src('yougov','YouGov',2,'consumer insight','yougov.com,yougov.co.uk',21,1,'Individua insight YouGov su intenzioni, budget e preferenze dei viaggiatori.','Sondaggi e consumer insight.'),
            self::src('data_appeal','Data Appeal',2,'destination intelligence','datappeal.io,thedataappealcompany.com',21,1,'Cerca insight Data Appeal su sentiment, reputazione destinazioni e domanda.','Destination intelligence.'),
            self::src('pinterest_predicts','Pinterest Predicts',2,'ispirazione visuale','pinterestpredicts.com,pinterest.com',30,1,'Usa Pinterest Predicts per segnali aspirazionali, visual trend e micro-nicchie travel.','Trend previsionali Pinterest.'),
        );
    }

    private static function src($key,$name,$priority,$category,$domains,$days,$enabled,$prompt,$description) {
        return compact('key','name','priority','category','domains','days','enabled','prompt','description');
    }
}
