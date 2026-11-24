# Privacy Policy for FieldPlus

**Effective Date:** October 10, 2026  
**Application Name:** FieldPlus  
**Application Package ID:** `com.fieldplus.app`  
**Version:** 1.3.3  

---

## 1. Overview
FieldPlus is an enterprise-grade mobile application designed for field surveys, asset inspections, and operational audits. This Privacy Policy outlines how FieldPlus collects, uses, stores, and protects data gathered from users and surveyors during their fieldwork.

By installing and using FieldPlus, you acknowledge and agree to the data collection and usage practices described in this policy.

---

## 2. Information We Collect
To facilitate accurate inspection reports and verify fieldwork, FieldPlus collects the following types of information:

* **Location Data (GPS):** Precise and coarse coordinates (latitude, longitude, altitude, and accuracy estimate) and journey waypoints captured during survey completion to provide verifiable proof of site presence.
* **Camera Photos and Videos:** Images and recorded video clips captured directly through the device camera or selected from media storage as inspection evidence.
* **Audio Recordings:** Audio captured synchronously with video recordings to preserve verbal surveyor notes and field site ambient context.
* **Digital Signatures:** Touch-drawn digital signature paths used to certify survey completion and client approvals.
* **Form Responses & Audit Data:** Custom form input values, checklists, inspection ratings, dropdown selections, notes, and timestamps.
* **Device & Technical Information:** Device model, Android OS version, app version, network connectivity status, and local database sync queue status.

---

## 3. Android Permissions & Justifications

| Permission | Android Category | Purpose / Feature |
| :--- | :--- | :--- |
| `ACCESS_FINE_LOCATION`<br>`ACCESS_COARSE_LOCATION` | Location | Captures exact GPS coordinates for field surveys and maps inspection sites. |
| `CAMERA` | Camera | Takes site photos, defect evidence, and inspection snapshots directly in forms. |
| `READ_MEDIA_IMAGES`<br>`READ_MEDIA_VIDEO` | Photos & Videos | Enables selecting existing photo or video evidence from the gallery for attachment to forms. |
| `RECORD_AUDIO` | Microphone | Records audio during video evidence capture for spoken inspector remarks. |
| `INTERNET`<br>`ACCESS_NETWORK_STATE` | Network | Synchronizes forms, attachments, and survey responses with your enterprise server. |
| `FOREGROUND_SERVICE`<br>`FOREGROUND_SERVICE_DATA_SYNC` | Background Processing | Ensures uninterrupted background upload and synchronization of heavy photo and video attachments. |
| `POST_NOTIFICATIONS` | Notifications | Notifies the user of sync progress, offline outbox status, and submission successes. |

---

## 4. How Data Is Used
Collected survey and inspection data is utilized strictly for:
1. Compiling and submitting authorized survey and inspection reports.
2. Geospatial validation and verification of inspection site attendance.
3. Offline queueing and subsequent synchronization with your organization's server.
4. Historical reporting and evidence verification inside the FieldPlus web administrative console.
5. Maintenance, fraud prevention, and operational integrity.

---

## 5. Data Storage, Security & Retention
* **Local Storage:** All un-synced submissions, photos, and videos reside strictly in the app's sandboxed private storage directory and private local Room SQLite database. They cannot be accessed by other applications installed on the device.
* **Transmission:** Data is transmitted directly to your organization's backend API endpoint using encrypted HTTPS / TLS protocols.
* **No Third-Party Sale:** We do not sell, rent, monetize, or broker personal information, survey responses, or media files to third-party advertisers or data brokers.
* **Data Deletion:** Local draft and synced submissions can be deleted directly within the app via **Submissions > Menu > Clear All Submissions**. Server-side retention is managed by your organization's system administrator in accordance with applicable retention guidelines.

---

## 6. Children's Privacy
FieldPlus is designed and intended strictly for enterprise, industrial, and commercial use by adult professionals. It does not knowingly collect information from children under 13 (or under 16 in the European Union).

---

## 7. Contact Us
For questions regarding this Privacy Policy or data protection inquiries:

* **App Support:** FieldPlus Development Team  
* **Email:** support@fieldplus.app  
* **Package ID:** `com.fieldplus.app`  
