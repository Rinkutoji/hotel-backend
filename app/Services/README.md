# app/Services

Empty in STEP 02.

Convention: a Service class is created only where real branching business
logic exists (e.g. `BookingService::createBooking()` handling the
multi-room transaction + availability check from Architecture Baseline
§9). Simple CRUD modules (Departments, Room Types) do NOT get a Service -
the Controller talks to the Model directly. Don't create a Service "for
consistency" if there's no logic to put in it.
