# DATA Patch for Pilotage Order and Pilot Event

Here the seeder data to execute data patch that resources from json

## How To execute

1. Clone this repository.

    `git clone https://github.com/frediansimanjuntak/OHS-pilotage-order-patch.git`

2. Move all file in folder "/seeds" into "{project-folder}/database/seeds".

3. Move all file in folder "/data" into "{project-folder}/database/data".

4. If folder "/data" not ready in the project folder, please create first before move the files.

5. Put data to the json file, the format like the current data in that file.

6. To run the seeder you can use this command, and data patch will execute.

    `php artisan db:seed --class=PilotageOrderSeeder`
    
    `php artisan db:seed --class=PilotEventSeeder`

